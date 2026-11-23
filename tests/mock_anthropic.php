<?php
// Falošný Anthropic API server pre testy: zaznamená požiadavku a vráti pripravenú odpoveď.
$body = file_get_contents('php://input');
file_put_contents(sys_get_temp_dir() . '/dm_mock_last_request.json', $body);
$req = json_decode($body, true);
$isActivity = str_contains(json_encode($req['system'] ?? ''), 'screenshoty');
$payload = $isActivity
    ? ['is_activity' => true, 'name' => 'Beh', 'kcal' => 612, 'duration_min' => 55, 'date' => '', 'comment' => 'Garmin – aktívne kalórie.']
    : ['items' => [
        ['name' => 'Vyprážaný rezeň', 'amount_g' => 150, 'kcal' => 420, 'protein_g' => 30, 'carbs_g' => 18, 'fat_g' => 25, 'fiber_g' => 1, 'confidence' => 'medium'],
        ['name' => 'Zemiakový šalát', 'amount_g' => 200, 'kcal' => 300, 'protein_g' => 4, 'carbs_g' => 30, 'fat_g' => 18, 'fiber_g' => 3, 'confidence' => 'medium'],
      ], 'comment' => 'Odhad bežnej porcie.'];
header('Content-Type: application/json');
echo json_encode([
    'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => $req['model'] ?? 'x',
    'content' => [['type' => 'text', 'text' => json_encode($payload, JSON_UNESCAPED_UNICODE)]],
    'stop_reason' => 'end_turn', 'stop_sequence' => null,
    'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
]);
