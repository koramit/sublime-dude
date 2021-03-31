<?php

namespace App;

use App\Models\VentiRecord;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class Venti
{
    public static function itnev($patients)
    {
        $medicineCases = [];
        foreach ($patients as $patient) {
            // if no hn in DB or hn discharged then create new case
            $case = VentiRecord::whereHn($patient['hn'])
                               ->whereNull('dismissed_at')
                               ->first();
            $los = explode(':', $patient['los']);
            unset($patient['los']);

            $minutes = (((int) $los[0]) ?? 0) * 60;
            $minutes += (((int) $los[1]) ?? 0);
            $encounteredAt = now()->addMinutes($minutes * -1);

            $hnCollection = collect($patients)->pluck('hn');

            if (! $case) { // double check on new ase
                $case = VentiRecord::where('no', 'like', $encounteredAt->format('ymdH').'%'.$patient['hn'])->first();
            }

            if (! $case) { // new case - create
                $patient += [
                    'no' => $encounteredAt->format('ymdHi').$patient['hn'],
                    'encountered_at' => $encounteredAt,
                ];
                if ($patient['medicine']) {
                    $patient += [
                        'tagged_med_at' => $encounteredAt,
                    ];
                }
                try {
                    $case = VentiRecord::create($patient);
                } catch (Exception $e) {
                    Log::error('create case error');
                    Log::error($patient);
                }
                $case->needSync = true;
            } else { // old case - update
                $updates = false;
                foreach ($patient as $key => $value) {
                    if ($case->$key != $value) {
                        $case->$key = $value;
                        $updates = true;
                        if ($key == 'dx') {
                            Log::info('event dx change '.$case->no);
                        }
                        if ($key == 'medicine' && $value) {
                            Log::info('event case tagged med '.$case->no);
                            $case->tagged_med_at = now();
                        }
                    }
                }
                if ($hnCollection->search($case->hn) === false) { // $case not in collection
                    $case->dismissed_at = now();
                    $updates = true;
                }
                try {
                    if ($updates) {
                        $case->save();
                        $case->needSync = true;
                    }
                } catch (Exception $e) {
                    Log::error('update case error');
                    Log::error($patient);
                }
            }

            if ($case->medicine) {
                $medicineCases[] = $case;
            }
        }

        // $list = collect($patients)->pluck('hn')->toArray();
        $list = $hnCollection->toArray();
        Cache::put('latestlist', $list);
        foreach ($medicineCases as $case) {
            if ($case->needSync) {
                Log::info('Need Sync : '.$case->no);
            }
        }

        static::monitor();
    }

    public static function future($patients)
    {
        $medicineCases = [];
        foreach ($patients as $patient) {
            $case = VentiRecord::whereHn($patient['hn'])
                               ->whereNull('dismissed_at')
                               ->first();

            // add case to history if its not exists
            $history = Cache::get('venti-history', collect([]));
            if (! $case) {
                $dismissedAt = Carbon::parse($patient['dismissed_at'], 'asia/bangkok')->tz('utc');
                $old = VentiRecord::whereHn($patient['hn'])
                                  ->whereDismissedAt($dismissedAt)
                                  ->first();
                if ($old) { // case already exists and discharged
                    continue;
                }

                $old = $history->firstWhere('hn', $patient['hn']);
                if (! $old) {
                    $history->push($patient);
                    Cache::put('venti-history', $history);
                }
                continue;
            }

            // case found - remove it from history cache
            $history = $history->filter(function ($record) use ($patient) {
                return $record['hn'] != $patient['hn'];
            });
            Cache::put('venti-history', $history);

            // update case
            $dirty = false;
            foreach (['movement', 'cc', 'dx', 'insurance', 'outcome'] as $field) {
                if ($patient[$field] && ($patient[$field] != '') && ($case->$field != $patient[$field])) {
                    $case->$field = $patient[$field];
                    $dirty = true;
                }
            }

            foreach (['encountered_at', 'dismissed_at'] as $field) {
                $timestamp = Carbon::parse($patient[$field], 'asia/bangkok')->tz('utc');
                if ((! $case->$field) || ($case->$field->format('Y-m-d H:i') != $timestamp->format('Y-m-d H:i'))) {
                    $case->$field = $timestamp;
                    $dirty = true;
                    Log::info('Update timestamp form history');
                }
            }

            if ($dirty) {
                $case->save();
                Log::info('Need Sync : '.$case->no);
                if ($case->medicine) {
                    $medicineCases[] = $case;
                }
            }
        }

        if (count($medicineCases)) {
            //update
        }
    }

    public static function monitor()
    {
        $now = [
            'cases' => VentiRecord::count(),
            'dc' => VentiRecord::wherenotNull('dismissed_at')->count(),
            'med' => VentiRecord::whereMedicine(true)->count(),
            'venti' => count(Cache::get('latestlist', [])),
        ];
        $monitor = Cache::get('venti-monitor', []);

        $alertAt = (int) env('VENTI_ALERT');
        if (count($monitor) < $alertAt) {
            $monitor[] = $now;
            Cache::put('venti-monitor', $monitor);

            return;
        }

        if ($monitor[0]['cases'] != $now['cases'] ||
            $monitor[0]['dc'] != $now['dc'] ||
            $monitor[0]['med'] != $now['med'] ||
            $monitor[0]['venti'] != $now['venti'] ||
            count($monitor) > ($alertAt + 10)
        ) {
            Cache::put('venti-monitor', []);

            return;
        }

        $monitor[] = $now;
        Cache::put('venti-monitor', $monitor);
        Log::critical('venti not update for '.count($monitor).' iterations');
    }
}
