<?php

namespace App;

use App\Models\VentiRecord;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Venti
{
    public static function itnev($patients)
    {
        foreach ($patients as $patient) {
            // Sometime, there are more than one case for a HN at the same time
            // maybe Human error so, we have to use encounter date for
            // detecting a specific case because just HN and
            // not discharge yet is not enough
            $los = explode(':', $patient['los']);
            unset($patient['los']);
            $minutes = (((int) $los[0]) ?? 0) * 60;
            if (isset($los[1]) && ((int) $los[1]) < 0) { // sometime, whiteboard show LOS x:-1
                continue;
            }
            $minutes += (((int) $los[1]) ?? 0);
            $minutes = $minutes < 0 ? 0 : ($minutes * -1);
            $encounteredAt = now()->addMinutes($minutes);

            // remove fields
            unset($patient['bed']);
            unset($patient['dx']);

            // if no hn in DB or hn discharged then create new case
            $case = VentiRecord::where('no', 'like', $encounteredAt->format('ymdH').'%'.$patient['hn'])
                               ->whereNull('dismissed_at')
                               ->get();

            if ($case->count() > 1) {
                Log::critical('MULTIPLE CASES OF A HN AT THE SAMETIME!!!');
                continue;
            } elseif ($case->count() === 1) {
                $case = $case[0];
            } else {
                $case = null;
            }

            if (! $case) { // new case - create
                $duplicateCase = VentiRecord::whereHn($patient['hn'])
                                            ->whereNull('dismissed_at')
                                            ->first();

                if ($duplicateCase) {
                    continue;
                }

                $patient += [
                    'no' => $encounteredAt->format('ymdHi').$patient['hn'],
                    'encountered_at' => $encounteredAt,
                ];
                if ($patient['medicine']) {
                    $patient += [
                        'tagged_med_at' => $encounteredAt,
                        'need_sync' => true, // sync med case only
                    ];
                }
                try {
                    $case = VentiRecord::create($patient);
                } catch (Exception $e) {
                    Log::error('create case error => '.$patient['no']);
                    continue;
                }
            } else { // old case - update
                $updates = false;
                foreach ($patient as $key => $value) {
                    if ($case->$key != $value) {
                        $case->$key = $value;
                        $updates = true;
                        if ($key == 'medicine' && $value) {
                            $case->tagged_med_at = now();
                        }
                    }
                }
                try {
                    if ($updates) {
                        if ($case->medicine) {
                            $case->need_sync = true; // sync med case only
                        }
                        $case->save();
                    }
                } catch (Exception $e) {
                    Log::error('update case error => '.$case->no);
                    continue;
                }
            }
        }

        // breaker
        $lastcounts = Cache::get('lastcounts', collect([]));
        $list = collect($patients)->pluck('hn')->toArray();
        $listCount = count($list);
        $lastcounts->push($listCount);
        if ($lastcounts->count() > 3) {
            $lastcounts->shift();
        }
        Cache::put('lastcounts', $lastcounts);

        if ($lastcounts->avg() - $listCount <= 5) {
            // dismiss cases thoses removed from whiteboard
            VentiRecord::whereNull('dismissed_at')
                    ->whereNotIn('hn', $list)
                    ->get()
                    ->each(function ($case) {
                        $case->dismissed_at = now();
                        if ($case->medicine) {
                            $case->need_sync = true; // sync med case only
                        }
                        $case->save();
                    });
        }

        // TODO sync data
    }

    public static function future($patients)
    {
        return [];
    }

    public static function monitor()
    {
        $now = [
            'cases' => VentiRecord::count(),
            'dc' => VentiRecord::wherenotNull('dismissed_at')->count(),
            'med' => VentiRecord::whereMedicine(true)->count(),
            'venti' => Cache::get('lastcounts')->last(),
        ];
        $monitor = Cache::get('venti-monitor', []);

        $alertAt = (int) env('VENTI_ALERT');
        if (count($monitor) < $alertAt) {
            $monitor[] = $now;
            Cache::put('venti-monitor', $monitor);

            return 'ok';
        }

        if ($monitor[0]['cases'] != $now['cases'] ||
            $monitor[0]['dc'] != $now['dc'] ||
            $monitor[0]['med'] != $now['med'] ||
            $monitor[0]['venti'] != $now['venti'] ||
            count($monitor) > ($alertAt * 1.1)
        ) {
            Cache::put('venti-monitor', []);

            return 'ok';
        }

        $monitor[] = $now;
        Cache::put('venti-monitor', $monitor);
        Log::critical('venti not update for '.count($monitor).' minutes ('.$now['venti'].') cases');

        return 'need attention';
    }

    public static function rotateCase()
    {
        $case = VentiRecord::whereNull('dismissed_at')
                           ->orderByDesc('medicine')
                           ->orderBy('updated_at')
                           ->first();
        if (! $case) {
            return ['hn' => false];
        }

        $case->touch();

        return ['hn' => $case->hn, 'no' => $case->no];
    }

    public static function rotateHistory()
    {
        $case = VentiRecord::whereNotNull('dismissed_at')
                           ->whereNull('outcome')
                           ->orderByDesc('medicine')
                           ->orderBy('encountered_at')
                           ->first();

        if (! $case) {
            // search 'outcome' => 'case removed' in case of accidently DC from whiteboard
            $case = VentiRecord::whereOutcome('case removed')
                               ->orderBy('updated_at')
                               ->first();

            if (! $case) {
                return ['hn' => false];
            }

            $case->touch();
        }

        $lastRotate = Cache::get('venti-last-history-search', '');
        if ($case->no != $lastRotate) {
            Cache::put('venti-last-history-search', $case->no);
            $daysInReal = now()->diffInHours($case->encountered_at) / 24;
            $pageStart = ((int) ($daysInReal) + 1) * 6;

            return [
                'hn' => $case->hn,
                'no' => $case->no,
                'medicine' => $case->medicine,
                'pageStart' => $pageStart,
                'maxPage' => (int) ($daysInReal * 12),
                'timestamp' => $case->encountered_at->tz('asia/bangkok')->format('Y-m-d H:i'),
                'timer' => $case->encountered_at->tz('asia/bangkok')->format('H:i'),
            ];
        }

        Cache::put('venti-last-history-search', ''); // give it second chance
        $case->update(['outcome' => 'case removed']);

        return ['hn' => false];
    }

    public static function profile($profile)
    {
        $case = VentiRecord::whereNo($profile['no'])->whereHn($profile['hn'])->first();

        if (! $case) {
            return;
        }

        unset($profile['found']);
        if ($profile['encountered_at']) {
            try {
                if (strpos(strtolower($profile['encountered_at']), 'today') !== false) {
                    // $profile['encountered_at'] = strpos($profile['encountered_at'], '-') === false ? $profile['encountered_at'] : trim(explode('-', $profile['encountered_at'])[1]);
                    $time = explode(' ', $profile['encountered_at'])[0];
                    $case->encountered_at = Carbon::parse('today '.$time, 'asia/bangkok')->tz('UTC');
                } else {
                    $case->encountered_at = Carbon::createFromFormat('H:i D, d M y', $profile['encountered_at'], 'asia/bangkok')->tz('utc');
                }
                $updates = true;
            } catch (Exception $e) {
                Log::critical($profile['encountered_at']);
            }
        }
        unset($profile['encountered_at']);
        unset($profile['hn']);
        unset($profile['no']);

        $updates = false;

        if (isset($profile['dismissed_at'])) {
            try {
                if (strpos(strtolower($profile['dismissed_at']), 'today') !== false) {
                    // $profile['dismissed_at'] = strpos($profile['dismissed_at'], '-') === false ? $profile['dismissed_at'] : trim(explode('-', $profile['dismissed_at'])[1]);
                    $time = explode(' ', $profile['dismissed_at'])[0];
                    $case->dismissed_at = Carbon::parse('today '.$time, 'asia/bangkok')->tz('UTC');
                } else {
                    $case->dismissed_at = Carbon::createFromFormat('H:i D, d M y', $profile['dismissed_at'], 'asia/bangkok')->tz('utc');
                }
                $updates = true;
            } catch (Exception $e) {
                Log::critical($profile['dismissed_at']);
            }
            unset($profile['dismissed_at']);
        }

        foreach ($profile as $key => $value) {
            if ($value && $case->$key != $value) {
                $case->$key = $value;
                $updates = true;
            }
        }

        if ($updates) {
            $case->need_sync = true; // tag sync then, it will sync in whitlboard handler
            $case->save();
        }
    }

    public static function sync()
    {
        $cases = VentiRecord::whereMedicine(true)
                            ->whereNeedSync(true)
                            ->get()
                            ->transform(function ($case) {
                                $triage = $case->clean_triage;

                                return [
                                    'no' => $case->no,
                                    'location' => $case->location,
                                    'hn' => $case->hn,
                                    'cc' => $case->cc,
                                    'dx' => $case->dx,
                                    'via' => $triage['via'] ?? null,
                                    'severity' => $triage['severity'] ?? null,
                                    'mobility' => $triage['mobility'] ?? null,
                                    'counter' => $case->counter,
                                    'insurance' => $case->insurance,
                                    'outcome' => $case->outcome,
                                    'vital_signs' => $case->clean_vital_signs,
                                    'remark' => $case->remark,
                                    'encountered_at' => $case->encountered_at ? $case->encountered_at->format('Y-m-d H:i:s') : null,
                                    'dismissed_at' => $case->dismissed_at ? $case->dismissed_at->format('Y-m-d H:i:s') : null,
                                    'tagged_med_at' => $case->tagged_med_at ? $case->tagged_med_at->format('Y-m-d H:i:s') : null,
                                ];
                            });

        return $cases;
        // $response = Http::withHeaders(['token' => 'tokentoken'])
        //                 ->post('url', ['cases' => ]);
        // remove sync tag
    }
}
