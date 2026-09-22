
<?php
session_start();
require_once __DIR__ . '/rmq_client.php';
 
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
 
// CSRF token
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
 
// Names must match TheMealDB ingredient naming (underscores for spaces)
$pantryOptions = [
    'chicken_breast' => 'Chicken Breast',
    'ground_beef'    => 'Ground Beef',
    'bacon'          => 'Bacon',
    'salmon'         => 'Salmon',
    'shrimp'         => 'Shrimp',
    'eggs'           => 'Eggs',
    'rice'           => 'White Rice',
    'pasta'          => 'Pasta',
    'bread'          => 'Bread',
    'potatoes'       => 'Potatoes',
    'garlic'         => 'Garlic',
    'onion'          => 'Yellow Onion',
    'tomato'         => 'Tomatoes',
    'bell_pepper'    => 'Bell Pepper',
    'carrot'         => 'Carrots',
    'spinach'        => 'Spinach',
    'broccoli'       => 'Broccoli',
    'mushroom'       => 'Mushrooms',
    'butter'         => 'Butter',
    'milk'           => 'Milk',
    'cheddar_cheese' => 'Cheddar Cheese',
    'parmesan'       => 'Parmesan',
    'olive_oil'      => 'Olive Oil',
    'salt'           => 'Salt',
    'black_pepper'   => 'Black Pepper',
    'lemon'          => 'Lemon',
    'lime'           => 'Lime',
    'flour'          => 'Flour',
    'sugar'          => 'Sugar',
    'cinnamon'       => 'Cinnamon',
    'cumin'          => 'Cumin',
    'paprika'        => 'Paprika',
    'soy_sauce'      => 'Soy Sauce',
    'vinegar'        => 'Vinegar',
    'honey'          => 'Honey',
    'yogurt'         => 'Yogurt',
    'lettuce'        => 'Lettuce',
    'cucumber'       => 'Cucumber',
    'zucchini'       => 'Zucchini',
    'corn'           => 'Corn',
    'black_beans'    => 'Black Beans',
    'oats'           => 'Oats',
    'apple'          => 'Apple',
    'banana'         => 'Banana',
    'avocado'        => 'Avocado',
    'ginger'         => 'Ginger',
    'chili_powder'   => 'Chili Powder',
    'basil'          => 'Basil',
    'ketchup'        => 'Ketchup',
    'mustard'        => 'Mustard',
    'mayonnaise'     => 'Mayonnaise',
    'tortilla'       => 'Tortilla',
];
 
// Local, in-session mirror of pantry state so the UI can render quantities /
// shelf-life immediately after a round trip through the Database Node.
// The authoritative copy always lives behind the RabbitMQ pantry queues.
if (!isset($_SESSION['pantry_state']) || !is_array($_SESSION['pantry_state'])) {
    $_SESSION['pantry_state'] = [];
}
 
$isLoggedIn = !empty($_SESSION['user']);
 
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $flash  = ['type' => 'error', 'message' => 'Unknown action'];
 
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $flash = ['type' => 'error', 'message' => 'Invalid form token, please try again.'];
    } else {
        try {
            if ($action === 'logout') {
                $_SESSION = [];
                session_regenerate_id(true);
                $_SESSION['csrf'] = bin2hex(random_bytes(16));
                $flash = ['type' => 'ok', 'message' => 'Logged out.'];
 
            } else {
                $client = new RabbitRPCClient();
 
                if ($action === 'ping_test') {
                    $resp = $client->call('ping', ['sender' => 'FE_Node', 'timestamp' => time()]);
                    $flash = [
                        'type'    => ($resp['status'] ?? '') === 'error' ? 'error' : 'ok',
                        'message' => 'Received from RMQ: ' . json_encode($resp),
                    ];
 
                } elseif ($action === 'register') {
                    $username = trim($_POST['reg_username'] ?? '');
                    $password = $_POST['reg_password'] ?? '';
                    $confirm  = $_POST['reg_confirm'] ?? '';
 
                    if ($username === '' || $password === '') {
                        $flash = ['type' => 'error', 'message' => 'Username and password are required.'];
                    } elseif ($password !== $confirm) {
                        $flash = ['type' => 'error', 'message' => 'Passwords do not match.'];
                    } else {
                        // Database Node hashes + persists credentials; FE never stores plaintext.
                        $resp = $client->call('register', [
                            'username' => $username,
                            'password' => $password,
                        ]);
                        if (($resp['status'] ?? '') === 'ok') {
                            $flash = ['type' => 'ok', 'message' => 'Account created for ' . h($username) . '. You can sign in now.'];
                        } else {
                            $flash = ['type' => 'error', 'message' => $resp['message'] ?? 'Registration failed'];
                        }
                    }
 
                } elseif ($action === 'login') {
                    $resp = $client->call('login', [
                        'username' => trim($_POST['username'] ?? ''),
                        'password' => $_POST['password'] ?? '',
                    ]);
                    if (($resp['status'] ?? '') === 'ok') {
                        session_regenerate_id(true);
                        $_SESSION['user']  = $resp['user'] ?? trim($_POST['username']);
                        $_SESSION['token'] = $resp['session_token'] ?? null;
                        // Pull the persisted pantry state for this user on login.
                        $pantryResp = $client->call('pantry_get', ['session_token' => $_SESSION['token']]);
                        $_SESSION['pantry_state'] = $pantryResp['pantry'] ?? [];
                        $flash = ['type' => 'ok', 'message' => 'Welcome, ' . $_SESSION['user'] . '!'];
                    } else {
                        $flash = ['type' => 'error', 'message' => $resp['message'] ?? 'Login failed'];
                    }
 
                } elseif ($action === 'pantry_save') {
                    // Smart Pantry Inventory: search-box add, one ingredient at a time.
                    // Typed text is matched (case-insensitively) against the known
                    // ingredient list first, so predictive/autocompleted picks map
                    // to TheMealDB's canonical naming; anything unrecognized is
                    // normalized into a same-style key so users can still log
                    // pantry items TheMealDB doesn't know about.
                    $raw = trim($_POST['ingredient'] ?? '');
                    $qty = isset($_POST['qty']) ? (int)$_POST['qty'] : 1;
                    $items = [];
 
                    if ($raw !== '') {
                        $matchKey = null;
                        foreach ($pantryOptions as $key => $label) {
                            if (strcasecmp($label, $raw) === 0) {
                                $matchKey = $key;
                                break;
                            }
                        }
                        if ($matchKey === null) {
                            $custom = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $raw), '_'));
                            $matchKey = $custom !== '' ? $custom : null;
                        }
                        if ($matchKey !== null) {
                            $items[$matchKey] = [
                                'label'    => $pantryOptions[$matchKey] ?? $raw,
                                'quantity' => max(1, $qty),
                            ];
                        }
                    }
 
                    if (!$items) {
                        $flash = ['type' => 'error', 'message' => 'Type or select an ingredient to add to your pantry.'];
                    } else {
                        $resp = $client->call('pantry_save', [
                            'items'         => $items,
                            'session_token' => $_SESSION['token'] ?? null,
                        ], 15);
                        if (($resp['status'] ?? '') === 'ok') {
                            // Merge into local mirror for immediate UI feedback.
                            $_SESSION['pantry_state'] = array_merge($_SESSION['pantry_state'], $items);
                            $flash = ['type' => 'ok', 'message' => 'Pantry updated: ' . count($items) . ' item(s) saved.'];
                        } else {
                            $flash = ['type' => 'error', 'message' => $resp['message'] ?? 'Could not save pantry.'];
                        }
                    }
 
                } elseif ($action === 'pantry_remove') {
                    $key = $_POST['item'] ?? '';
                    if ($key && isset($_SESSION['pantry_state'][$key])) {
                        $client->call('pantry_remove', [
                            'item'          => $key,
                            'session_token' => $_SESSION['token'] ?? null,
                        ]);
                        unset($_SESSION['pantry_state'][$key]);
                        $flash = ['type' => 'ok', 'message' => 'Removed ' . h($pantryOptions[$key] ?? $key) . ' from pantry.'];
                    } else {
                        $flash = ['type' => 'error', 'message' => 'Item not found in pantry.'];
                    }
 
                } elseif ($action === 'pantry_match') {
                    // Instant Recipe Matching against everything currently in the pantry.
                    $selected = array_keys($_SESSION['pantry_state']);
                    if (!$selected) {
                        $flash = ['type' => 'error', 'message' => 'Add items to your pantry first.'];
                    } else {
                        $resp = $client->call('pantry_match', [
                            'ingredients'   => $selected,
                            'session_token' => $_SESSION['token'] ?? null,
                        ], 15);
                        if (($resp['status'] ?? '') === 'ok') {
                            $_SESSION['last_meals'] = $resp['meals'] ?? [];
                            $flash = [
                                'type'    => 'ok',
                                'message' => 'Found ' . count($resp['meals'] ?? []) . ' matching recipe(s).',
                                'meals'   => $resp['meals'] ?? [],
                            ];
                        } else {
                            $flash = ['type' => 'error', 'message' => $resp['message'] ?? 'Matching failed'];
                        }
                    }
 
                } elseif ($action === 'grocery_list') {
                    // Missing Ingredient & Grocery Generator: diff selected recipes'
                    // required ingredients against current pantry state.
                    $mealIds = array_filter((array)($_POST['meal_id'] ?? []));
                    if (!$mealIds) {
                        $flash = ['type' => 'error', 'message' => 'Select at least one recipe to build a grocery list.'];
                    } else {
                        $resp = $client->call('grocery_list', [
                            'meal_ids'      => array_values($mealIds),
                            'have'          => array_keys($_SESSION['pantry_state']),
                            'session_token' => $_SESSION['token'] ?? null,
                        ], 15);
                        if (($resp['status'] ?? '') === 'ok') {
                            $flash = [
                                'type'    => 'ok',
                                'message' => 'Grocery list generated: ' . count($resp['missing'] ?? []) . ' item(s) needed.',
                                'meals'   => $_SESSION['last_meals'] ?? [],
                                'missing' => $resp['missing'] ?? [],
                            ];
                        } else {
                            $flash = ['type' => 'error', 'message' => $resp['message'] ?? 'Could not generate grocery list'];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Log details server-side, show a generic message to the user
            error_log('FE error: ' . $e->getMessage());
            $flash = ['type' => 'error', 'message' => 'Could not reach the backend. Check RabbitMQ connectivity.'];
        }
    }
 
    // Post/Redirect/Get
    $_SESSION['flash'] = $flash;
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}
 
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$isLoggedIn = !empty($_SESSION['user']);
$pantryState = $_SESSION['pantry_state'];
$lastMeals = $flash['meals'] ?? ($_SESSION['last_meals'] ?? []);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Chef's Best Friend - IT490</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; background-color: #202124; color: #e8eaed; }
        h1, h3, h4 { color: #f1f3f4; }
        a { color: #8ab4f8; }
        .card { background: #2c2d30; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.4); margin-bottom: 20px; border: 1px solid #3c3d40; }
        button { background-color: #007bff; color: white; border: none; padding: 10px 16px; border-radius: 4px; cursor: pointer; }
        button.secondary { background-color: #5f6368; }
        button.danger { background-color: #dc3545; padding: 4px 10px; font-size: 12px; }
        input[type="text"], input[type="password"], input[type="number"] {
            background-color: #3c3d40; color: #e8eaed; border: 1px solid #55565a; border-radius: 4px; padding: 6px 8px;
        }
        .pantry-search { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin: 10px 0; }
        .pantry-search input[type="text"] { flex: 1; min-width: 200px; }
        .pantry-search input[type="number"] { width: 80px; }
        .status { padding: 10px; border-radius: 4px; margin-bottom: 20px; }
        .status.ok { background: #1e3a2b; color: #a6e9bd; border: 1px solid #2e6b47; }
        .status.error { background: #402326; color: #f5a3a8; border: 1px solid #7a3338; }
        .meals { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; margin-top: 12px; }
        .meal img { width: 100%; border-radius: 6px; } .meal span { display: block; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #3c3d40; font-size: 14px; }
        .auth-flex { display: flex; gap: 24px; flex-wrap: wrap; }
        .auth-flex form { flex: 1; min-width: 220px; display: flex; flex-direction: column; gap: 8px; }
        .missing { background: #4a3f1c; }
        .muted { color: #9aa0a6; }
    </style>
</head>
<body>
    <h1>Chef's Best Friend 🍳</h1>
    <p class="muted">Distributed pantry &amp; recipe matching </p>
 
    <?php if ($flash): ?>
        <div class="status <?= h($flash['type']) ?>"><strong>Response:</strong> <?= h($flash['message']) ?></div>
    <?php endif; ?>
 
    <div class="card">
        <h3>User Account</h3>
        <?php if ($isLoggedIn): ?>
            <p>Signed in as <strong><?= h($_SESSION['user']) ?></strong></p>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="logout">
                <button type="submit">Sign Out</button>
            </form>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="login">
                <input type="text" name="username" placeholder="Username" required>
                <input type="password" name="password" placeholder="Password" required>
                <button type="submit">Sign In</button>
            </form>
            <p class="muted" style="margin-top:12px;">
                Don't have an account? <a href="#register">Create an Account</a>
            </p>
        <?php endif; ?>
    </div>
 
    <div class="card">
        <h3>Smart Pantry Inventory</h3>
        <form method="POST" class="pantry-search">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="pantry_save">
            <input
                type="text"
                name="ingredient"
                list="ingredient-options"
                placeholder="Search for an ingredient…"
                autocomplete="off"
                required
            >
            <datalist id="ingredient-options">
                <?php foreach ($pantryOptions as $label): ?>
                    <option value="<?= h($label) ?>">
                <?php endforeach; ?>
            </datalist>
            <input type="number" name="qty" min="1" value="1" title="Quantity">
            <button type="submit">Add to Pantry</button>
        </form>
 
        <?php if ($pantryState): ?>
            <table>
                <tr><th>Ingredient</th><th>Qty</th><th></th></tr>
                <?php foreach ($pantryState as $key => $item): ?>
                    <tr>
                        <td><?= h($item['label'] ?? $key) ?></td>
                        <td><?= h((string)($item['quantity'] ?? 1)) ?></td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                                <input type="hidden" name="action" value="pantry_remove">
                                <input type="hidden" name="item" value="<?= h($key) ?>">
                                <button type="submit" class="danger">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p class="muted">Your pantry is empty. Search for an ingredient above to add it.</p>
        <?php endif; ?>
    </div>
 
    <div class="card">
        <h3>Instant Recipe Matching</h3>
        <form method="POST">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="pantry_match">
            <button type="submit">Find Recipes From My Pantry</button>
        </form>
 
        <?php if ($lastMeals): ?>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="grocery_list">
                <div class="meals">
                    <?php foreach ($lastMeals as $m): ?>
                        <div class="meal">
                            <?php if (!empty($m['thumb'])): ?>
                                <img src="<?= h($m['thumb']) ?>" alt="">
                            <?php endif; ?>
                            <label>
                                <input type="checkbox" name="meal_id[]" value="<?= h($m['id'] ?? '') ?>">
                                <span><?= h($m['name'] ?? 'Unnamed') ?></span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p style="margin-top:12px;">
                    <button type="submit">Generate Grocery List for Selected Recipes</button>
                </p>
            </form>
        <?php endif; ?>
 
        <?php if (!empty($flash['missing'])): ?>
            <h4>Missing Ingredients / Grocery List</h4>
            <table>
                <tr><th>Ingredient</th><th>Needed</th></tr>
                <?php foreach ($flash['missing'] as $g): ?>
                    <tr class="missing">
                        <td><?= h($g['name'] ?? '') ?></td>
                        <td><?= h($g['amount'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
 
    <?php if (!$isLoggedIn): ?>
    <div class="card" id="register">
        <h3>Create an Account</h3>
        <form method="POST">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="register">
            <input type="text" name="reg_username" placeholder="Choose a username" required>
            <input type="password" name="reg_password" placeholder="Password" required minlength="8">
            <input type="password" name="reg_confirm" placeholder="Confirm password" required minlength="8">
            <button type="submit" class="secondary">Register</button>
        </form>
    </div>
    <?php endif; ?>
 
    <div class="card">
        <h3>Communication Layer Test</h3>
        <form method="POST">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="ping_test">
            <button type="submit">Send AMQP Ping to Cluster</button>
        </form>
    </div>
</body>
</html>
 
