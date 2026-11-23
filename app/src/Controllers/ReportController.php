<?php
declare(strict_types=1);

namespace App\Controllers;

use App\{Auth, Db, HttpError, Nutrition, Validate};

final class ReportController
{
    /**
     * Report za obdobie [from, to]. Do štatistík sa rátajú iba uplynulé dni so záznamom
     * (dnešný deň sa zobrazí, ale nehodnotí sa, keďže ešte prebieha).
     */
    public static function range(): array
    {
        $u = Auth::require();
        $uid = (int) $u['id'];
        $from = Validate::date($_GET, 'from');
        $to = Validate::date($_GET, 'to');
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $start = new \DateTimeImmutable($from);
        $end = new \DateTimeImmutable($to);
        if ($start->diff($end)->days > 400) {
            throw new HttpError(422, 'Obdobie môže mať najviac 400 dní');
        }
        $today = date('Y-m-d');
        $p = ['u' => $uid, 'f' => $from, 't' => $to];

        $sums = [];
        foreach (Db::all('SELECT entry_date, COUNT(*) AS n, SUM(kcal) AS kcal, SUM(protein) AS protein, SUM(carbs) AS carbs, SUM(fat) AS fat, SUM(fiber) AS fiber
                FROM entries WHERE user_id = :u AND entry_date BETWEEN :f AND :t GROUP BY entry_date', $p) as $r) {
            $sums[$r['entry_date']] = $r;
        }
        $training = [];
        foreach (Db::all('SELECT log_date, SUM(kcal) AS kcal FROM activities WHERE user_id = :u AND log_date BETWEEN :f AND :t GROUP BY log_date', $p) as $r) {
            $training[$r['log_date']] = (float) $r['kcal'];
        }
        $water = [];
        foreach (Db::all('SELECT log_date, ml FROM water_logs WHERE user_id = :u AND log_date BETWEEN :f AND :t', $p) as $r) {
            $water[$r['log_date']] = (int) $r['ml'];
        }
        $weights = [];
        foreach (Db::all('SELECT log_date, kg FROM weights WHERE user_id = :u AND log_date BETWEEN :f AND :t ORDER BY log_date', $p) as $r) {
            $weights[$r['log_date']] = (float) $r['kg'];
        }
        $history = Nutrition::goalHistory($uid);

        $days = [];
        $eval = ['kcal' => [], 'protein' => [], 'carbs' => [], 'fat' => [], 'fiber' => []];
        $acc = Nutrition::emptyTotals();
        $accGoal = Nutrition::emptyTotals();
        $evaluated = 0;
        $waterAcc = 0;
        $waterDays = 0;
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            $ds = $d->format('Y-m-d');
            if ($ds > $today) {
                $days[] = ['date' => $ds, 'future' => true];
                continue;
            }
            $s = $sums[$ds] ?? null;
            $totals = Nutrition::emptyTotals();
            if ($s) {
                foreach (Nutrition::KEYS as $k) {
                    $totals[$k] = round((float) $s[$k], 1);
                }
            }
            $goal = Nutrition::withTraining(Nutrition::goalFromHistory($history, $ds), $training[$ds] ?? 0);
            $status = [];
            $counted = $s !== null && $ds < $today;
            foreach (Nutrition::KEYS as $k) {
                $status[$k] = $goal && $s ? Nutrition::evaluate($k, $totals[$k], (float) $goal[$k]) : null;
                if ($counted && $status[$k] !== null) {
                    $eval[$k][] = $status[$k];
                }
            }
            if ($counted) {
                $evaluated++;
                foreach (Nutrition::KEYS as $k) {
                    $acc[$k] += $totals[$k];
                    $accGoal[$k] += $goal ? (float) $goal[$k] : 0;
                }
                if (isset($water[$ds])) {
                    $waterAcc += $water[$ds];
                    $waterDays++;
                }
            }
            $days[] = [
                'date' => $ds,
                'logged' => $s !== null,
                'entries' => $s ? (int) $s['n'] : 0,
                'in_progress' => $ds === $today,
                'totals' => $totals,
                'goal' => $goal,
                'training_kcal' => $training[$ds] ?? 0,
                'water_ml' => $water[$ds] ?? 0,
                'weight_kg' => $weights[$ds] ?? null,
                'status' => $status,
            ];
        }

        $counts = [];
        foreach ($eval as $k => $list) {
            $c = array_count_values($list);
            $counts[$k] = ['in' => $c['in'] ?? 0, 'over' => $c['over'] ?? 0, 'under' => $c['under'] ?? 0];
        }
        $avg = fn(array $a) => $evaluated ? array_map(fn($v) => round($v / $evaluated, 1), $a) : null;
        $wv = array_values($weights);
        return [
            'from' => $from,
            'to' => $to,
            'days' => $days,
            'summary' => [
                'days_total' => count(array_filter($days, fn($d) => empty($d['future']))),
                'days_evaluated' => $evaluated,
                'average' => $avg($acc),
                'average_goal' => $avg($accGoal),
                'counts' => $counts,
                'water_avg' => $waterDays ? (int) round($waterAcc / $waterDays) : null,
                'training_total' => array_sum($training),
                'weight' => $wv ? ['first' => $wv[0], 'last' => end($wv), 'change' => round(end($wv) - $wv[0], 1)] : null,
            ],
        ];
    }
}
