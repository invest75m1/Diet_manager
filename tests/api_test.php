<?php
// Integračný test API. Použitie: php tests/api_test.php http://127.0.0.1:8099 admin heslo1234
declare(strict_types=1);

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8099', '/');
$adminUser = $argv[2] ?? 'admin';
$adminPass = $argv[3] ?? 'heslo1234';
$fail = 0;
$jars = [];

function req(string $who, string $method, string $route, ?array $json = null, ?array $multipart = null): array
{
    global $base, $jars;
    $jars[$who] ??= tempnam(sys_get_temp_dir(), 'jar');
    $ch = curl_init($base . '/api.php?r=' . str_replace('?', '&', $route));
    $headers = ['X-Requested-With: DietManager'];
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_COOKIEJAR => $jars[$who], CURLOPT_COOKIEFILE => $jars[$who]];
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($json);
    } elseif ($multipart !== null) {
        $opts[CURLOPT_POSTFIELDS] = $multipart;
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$code, json_decode($body, true) ?? $body];
}

function check(string $label, bool $ok, mixed $info = null): void
{
    global $fail;
    echo ($ok ? "  ok   " : "  FAIL ") . $label . ($ok ? '' : ' → ' . json_encode($info, JSON_UNESCAPED_UNICODE)) . "\n";
    if (!$ok) $fail++;
}

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

echo "Auth\n";
[$c, $r] = req('a', 'GET', '/auth/me');
check('me bez prihlásenia', $c === 200 && $r['user'] === null, $r);
[$c, $r] = req('a', 'POST', '/auth/login', ['username' => $adminUser, 'password' => 'zle-heslo']);
check('zlé heslo = 401', $c === 401, $r);
[$c, $r] = req('a', 'POST', '/auth/login', ['username' => strtoupper($adminUser), 'password' => $adminPass, 'remember' => true]);
check('prihlásenie admina (veľké písmená v mene)', $c === 200 && $r['user']['is_admin'] === true, $r);
[$c, $r] = req('x', 'POST', '/goal', ['kcal' => 2000]);
check('zápis bez prihlásenia = 401', $c === 401, $r);

echo "Admin\n";
req('a', 'DELETE', '/admin/users/999');
[$c, $r] = req('a', 'POST', '/admin/users', ['username' => 'jana', 'display_name' => 'Jana', 'password' => 'start1234']);
check('vytvorenie užívateľa', $c === 200 && $r['id'] > 0, $r);
$janaId = $r['id'] ?? 0;
[$c, $r] = req('a', 'POST', '/admin/users', ['username' => 'jana', 'password' => 'start1234']);
check('duplicitné meno = 422', $c === 422, $r);
[$c, $r] = req('a', 'POST', '/admin/users', ['username' => 'peter', 'password' => 'kratke']);
check('krátke heslo = 422', $c === 422, $r);
[$c, $r] = req('a', 'GET', '/admin/users');
check('zoznam užívateľov', $c === 200 && count($r['users']) >= 2, $r);

echo "Prvé prihlásenie užívateľa\n";
[$c, $r] = req('j', 'POST', '/auth/login', ['username' => 'jana', 'password' => 'start1234']);
check('jana prihlásená, musí zmeniť heslo', $c === 200 && $r['user']['must_change_password'] === true, $r);
[$c, $r] = req('j', 'GET', '/day');
check('pred zmenou hesla prístup zamietnutý', $c === 403 && $r['code'] === 'must_change_password', $r);
[$c, $r] = req('j', 'GET', '/admin/users');
check('nie-admin nemá prístup k správe', $c === 403, $r);
[$c, $r] = req('j', 'POST', '/auth/password', ['current_password' => 'start1234', 'new_password' => 'nove-heslo-1']);
check('zmena hesla', $c === 200 && $r['user']['must_change_password'] === false, $r);
[$c, $r] = req('j', 'GET', '/day');
check('po zmene hesla prístup povolený', $c === 200, $r);

echo "Ciele\n";
[$c, $r] = req('j', 'POST', '/goal/suggest', ['sex' => 'female', 'birth_year' => 1990, 'height_cm' => 168, 'weight_kg' => 65, 'activity_level' => 'light', 'goal_type' => 'lose']);
check('návrh cieľa', $c === 200 && $r['suggestion']['kcal'] > 1200, $r);
[$c, $r] = req('j', 'POST', '/goal', ['kcal' => 2000, 'protein' => 120, 'carbs' => 220, 'fat' => 70, 'fiber' => 30, 'water_ml' => 2500, 'valid_from' => date('Y-m-d', strtotime('-10 days'))]);
check('uloženie cieľa', $c === 200 && $r['goal']['kcal'] == 2000, $r);

echo "Potraviny a recepty\n";
[$c, $r] = req('j', 'POST', '/foods', ['name' => 'Ovsené vločky', 'kcal' => 370, 'protein' => 13, 'carbs' => 60, 'fat' => 7, 'fiber' => 10, 'serving_name' => 'miska', 'serving_g' => 60]);
check('vlastná potravina', $c === 200 && $r['food']['id'] > 0, $r);
$oats = $r['food']['id'];
[$c, $r] = req('j', 'POST', '/foods', ['name' => 'Mlieko 1,5%', 'kcal' => '46', 'protein' => '3,4', 'carbs' => 4.8, 'fat' => 1.5]);
$milk = $r['food']['id'];
check('desatinná čiarka', $c === 200 && $r['food']['protein'] == 3.4, $r);
[$c, $r] = req('j', 'POST', '/recipes', ['name' => 'Ovsená kaša', 'portions' => 2, 'items' => [['food_id' => $oats, 'grams' => 100], ['food_id' => $milk, 'grams' => 300]]]);
check('recept', $c === 200 && $r['food']['is_recipe'] && $r['food']['serving_g'] == 200 && abs($r['food']['kcal'] - (370 + 138) / 4) < 0.1, $r);
$recipe = $r['food']['id'];
[$c, $r] = req('j', 'GET', '/foods/search?q=' . urlencode('ovsen'));
check('vyhľadávanie', $c === 200 && count($r['foods']) === 2, $r);
[$c, $r] = req('a', 'GET', '/foods/search?q=' . urlencode('ovsen'));
check('recepty a potraviny sú súkromné', $c === 200 && count($r['foods']) === 0, $r);
[$c, $r] = req('j', 'POST', "/foods/$oats/favorite");
check('obľúbené', $c === 200 && $r['favorite'] === true, $r);

echo "Záznamy\n";
[$c, $r] = req('j', 'POST', '/entries', ['date' => $today, 'time' => '07:30', 'food_id' => $recipe, 'amount_g' => 200]);
check('záznam z receptu', $c === 200 && count($r['ids']) === 1, $r);
[$c, $r] = req('j', 'POST', '/entries', ['date' => $today, 'time' => '12:15', 'source' => 'ai_text', 'items' => [
    ['name' => 'Kuracie prsia', 'amount_g' => 150, 'kcal' => 250, 'protein' => 46, 'carbs' => 0, 'fat' => 5, 'source' => 'ai_text'],
    ['name' => 'Ryža', 'amount_g' => 200, 'kcal' => 260, 'protein' => 5, 'carbs' => 56, 'fat' => 1, 'source' => 'ai_text'],
]]);
check('dávka záznamov', $c === 200 && count($r['ids']) === 2, $r);
$riceId = $r['ids'][1];
[$c, $r] = req('j', 'PUT', "/entries/$riceId", ['amount_g' => 100]);
check('úprava gramov prepočíta hodnoty', $c === 200 && $r['entry']['kcal'] == 130 && $r['entry']['meal'] === 'lunch', $r);
[$c, $r] = req('j', 'POST', '/entries', ['date' => $yesterday, 'time' => '19:00', 'name' => 'Pizza', 'kcal' => 2400, 'protein' => 80, 'carbs' => 280, 'fat' => 100]);
check('ručný záznam včera', $c === 200, $r);
[$c, $r] = req('j', 'POST', '/entries/copy', ['from_date' => $yesterday, 'to_date' => $today, 'meal' => 'dinner']);
check('kopírovanie večere zo včera', $c === 200 && $r['copied'] === 1, $r);
[$c, $r] = req('a', 'PUT', "/entries/$riceId", ['amount_g' => 500]);
check('cudzí záznam nedostupný', $c === 404, $r);

echo "Voda, váha, tréning\n";
[$c, $r] = req('j', 'POST', '/water', ['date' => $today, 'delta' => 250]);
[$c, $r] = req('j', 'POST', '/water', ['date' => $today, 'delta' => 500]);
check('voda', $c === 200 && $r['water_ml'] === 750, $r);
[$c, $r] = req('j', 'POST', '/weights', ['date' => $yesterday, 'kg' => '65,4']);
[$c, $r] = req('j', 'POST', '/weights', ['date' => $today, 'kg' => 65.1]);
check('váha', $c === 200, $r);
[$c, $r] = req('j', 'POST', '/activities', ['date' => $today, 'name' => 'Beh', 'kcal' => 450, 'duration_min' => 45]);
check('tréning', $c === 200, $r);

echo "Deň\n";
[$c, $r] = req('j', 'GET', '/day?date=' . $today);
check('deň: záznamy', $c === 200 && count($r['entries']) === 4, $r);
check('deň: cieľ navýšený o tréning', $r['goal']['kcal'] == 2450 && $r['goal']['protein'] == 120 && $r['goal']['carbs'] > 220, $r['goal']);
check('deň: súčet kcal', abs($r['totals']['kcal'] - (254 + 250 + 130 + 2400)) < 0.2, $r['totals']);

echo "Report\n";
[$c, $r] = req('j', 'GET', '/reports?from=' . date('Y-m-d', strtotime('-6 days')) . '&to=' . $today);
check('report týždeň', $c === 200 && count($r['days']) === 7, $r);
check('report: včera nad cieľom', $r['summary']['counts']['kcal']['over'] === 1 && $r['summary']['days_evaluated'] === 1, $r['summary']);
check('report: zmena váhy', $r['summary']['weight']['change'] == -0.3, $r['summary']['weight']);

echo "AI (mock)\n";
[$c, $r] = req('j', 'POST', '/ai/text', ['text' => 'rezeň so zemiakovým šalátom']);
if ($c === 503) {
    echo "  skip AI – nie je nastavený kľúč\n";
} else {
    check('AI text', $c === 200 && count($r['items']) === 2 && $r['items'][0]['protein'] == 30, $r);
    $img = imagecreatetruecolor(2000, 1500);
    imagefill($img, 0, 0, imagecolorallocate($img, 200, 120, 50));
    $tmp = tempnam(sys_get_temp_dir(), 'img') . '.jpg';
    imagejpeg($img, $tmp, 90);
    [$c, $r] = req('j', 'POST', '/ai/photo', null, ['image' => new CURLFile($tmp, 'image/jpeg', 'foto.jpg'), 'kind' => 'menu', 'description' => 'polovičná porcia']);
    check('AI fotka', $c === 200 && count($r['items']) === 2 && !empty($r['photo']), $r);
    $sent = json_decode((string) @file_get_contents(sys_get_temp_dir() . '/dm_mock_last_request.json'), true);
    $imgBlock = $sent['messages'][0]['content'][0] ?? [];
    check('požiadavka: obrázok base64 + media_type', ($imgBlock['type'] ?? '') === 'image' && ($imgBlock['source']['media_type'] ?? '') === 'image/jpeg', array_keys($imgBlock['source'] ?? []));
    check('požiadavka: model, effort, json_schema, fallbacks', ($sent['model'] ?? '') !== '' && ($sent['output_config']['effort'] ?? '') === 'low'
        && ($sent['output_config']['format']['type'] ?? '') === 'json_schema' && ($sent['fallbacks'] ?? '') === 'default', array_diff_key($sent ?? [], ['messages' => 1, 'system' => 1]));
    $info = getimagesizefromstring(base64_decode($imgBlock['source']['data'] ?? ''));
    check('obrázok pre AI zmenšený na ≤1568 px', $info && max($info[0], $info[1]) <= 1568, $info);
    $photo = $r['photo'];
    [$c, $r] = req('j', 'POST', '/entries', ['date' => $today, 'time' => '13:00', 'photo' => $photo, 'items' => [['name' => 'X', 'kcal' => 1, 'source' => 'ai_photo']]]);
    [$c2, $d] = req('j', 'GET', '/day?date=' . $today);
    $withPhoto = array_values(array_filter($d['entries'], fn($e) => $e['photo'] === $photo));
    check('záznam s fotkou', count($withPhoto) === 1, $d['entries']);
    [$c, $body] = req('a', 'GET', '/photo?f=' . urlencode($photo));
    check('cudzia fotka nedostupná', $c === 404, $c);
    [$c, $r] = req('j', 'POST', '/ai/activity', null, ['image' => new CURLFile($tmp, 'image/jpeg', 'garmin.jpg')]);
    check('AI tréning (Garmin)', $c === 200 && $r['kcal'] == 612 && $r['name'] === 'Beh', $r);
}

echo "Mazanie užívateľa\n";
[$c, $r] = req('a', 'POST', "/admin/users/$janaId/password", ['password' => 'reset12345']);
check('reset hesla adminom', $c === 200, $r);
[$c, $r] = req('j', 'GET', '/day');
check('po resete je stará relácia neplatná', $c === 401, $r);
[$c, $r] = req('a', 'PUT', "/admin/users/$janaId", ['is_active' => false]);
[$c, $r] = req('j', 'POST', '/auth/login', ['username' => 'jana', 'password' => 'reset12345']);
check('deaktivovaný účet sa neprihlási', $c === 403, $r);
[$c, $r] = req('a', 'DELETE', "/admin/users/$janaId");
check('zmazanie užívateľa', $c === 200, $r);
[$c, $r] = req('a', 'GET', '/admin/users');
check('užívateľ zmazaný', !in_array('jana', array_column($r['users'], 'username'), true), $r);

echo $fail ? "\n$fail testov zlyhalo\n" : "\nVšetky testy prešli\n";
exit($fail ? 1 : 0);
