<?php
session_start();
require_once 'db.php';

function cancelOrder($order_id, $user_id) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT status FROM orders WHERE id = ? AND user_id = ?");
    $stmt->execute([$order_id, $user_id]);
    $order = $stmt->fetch();
    if (!$order) return ['success' => false, 'error' => 'Заказ не найден'];
    if ($order['status'] !== 'new') return ['success' => false, 'error' => 'Заказ уже нельзя отменить'];
    $stmt = $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?");
    $stmt->execute([$order_id]);
    return ['success' => true];
}



$route = $_GET['route'] ?? null;
if ($route) {
    header('Content-Type: application/json');
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'POST' && isset($_GET['_method'])) {
        $method = strtoupper($_GET['_method']);
    }
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input && ($method === 'POST' || $method === 'PUT')) {
        $input = $_POST;
    }
    $pdo = getDB();

    // Создание заказа
    if ($route === 'order' && $method === 'POST') {
        try {
            $name = trim($input['name'] ?? '');
            $phone = trim($input['phone'] ?? '');
            $email = trim($input['email'] ?? '');
            $message = trim($input['message'] ?? '');
            $consent = isset($input['consent']) ? 1 : 0;
            $product = trim($input['product'] ?? '');
            $quantity = (int)($input['quantity'] ?? 0);
            $delivery = (int)($input['delivery'] ?? 0);
            $gift = (isset($input['gift']) && $input['gift']) ? 1 : 0;
            $organic = (isset($input['organic']) && $input['organic']) ? 1 : 0;
            $total = (int)($input['total'] ?? 0);

            $errors = [];
            if (empty($name)) $errors['name'] = 'Имя обязательно';
            if (empty($phone)) $errors['phone'] = 'Телефон обязателен';
            elseif (!preg_match('/^[\d\s\-\+\(\)]{10,20}$/', $phone)) $errors['phone'] = 'Некорректный телефон';
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Некорректный email';
            if (!$consent) $errors['consent'] = 'Необходимо согласие на обработку данных';
            if (empty($product)) $errors['product'] = 'Выберите продукт';
            if ($quantity < 1) $errors['quantity'] = 'Количество не менее 1';
            if ($total <= 0) $errors['total'] = 'Некорректная сумма';

            if (!empty($errors)) {
                http_response_code(400);
                echo json_encode(['errors' => $errors]);
                exit;
            }

            $pdo->beginTransaction();

            if (isset($_SESSION['user_id'])) {
                $userId = $_SESSION['user_id'];
                $login = null;
                $plainPassword = null;
                $stmt = $pdo->prepare("UPDATE users SET name=?, phone=?, email=?, message=?, consent=? WHERE id=?");
                $stmt->execute([$name, $phone, $email, $message, $consent, $userId]);
            } else {
                $login = generateUniqueLogin($pdo);
                $plainPassword = generatePassword();
                $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (login, password_hash, name, phone, email, message, consent) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$login, $hash, $name, $phone, $email, $message, $consent]);
                $userId = $pdo->lastInsertId();
                $_SESSION['user_id'] = $userId;
                $_SESSION['login'] = $login;
            }

            $stmt = $pdo->prepare("INSERT INTO orders (user_id, product_type, quantity, delivery_cost, gift_wrap, organic_cert, total_price) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $product, $quantity, $delivery, $gift, $organic, $total]);
            $orderId = $pdo->lastInsertId();
            $pdo->commit();

            $response = ['status' => 'created', 'order_id' => $orderId];
            if ($login && $plainPassword) {
                $response['login'] = $login;
                $response['password'] = $plainPassword;
            }
            http_response_code(201);
            echo json_encode($response);
        } catch (Exception $e) {
            if (isset($pdo)) $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // Обновление заказа
    if ($route === 'order' && $method === 'PUT' && isset($_GET['id'])) {
        try {
            if (!isset($_SESSION['user_id'])) {
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized']);
                exit;
            }
            $orderId = (int)$_GET['id'];
            $userId = $_SESSION['user_id'];
            $product = trim($input['product'] ?? '');
            $quantity = (int)($input['quantity'] ?? 0);
            $delivery = (int)($input['delivery'] ?? 0);
            $gift = isset($input['gift']) ? 1 : 0;
            $organic = isset($input['organic']) ? 1 : 0;
            $total = (int)($input['total'] ?? 0);
            $name = trim($input['name'] ?? '');
            $phone = trim($input['phone'] ?? '');
            $email = trim($input['email'] ?? '');
            $message = trim($input['message'] ?? '');
            $consent = isset($input['consent']) ? 1 : 0;

            $errors = [];
            if (empty($product)) $errors['product'] = 'Выберите продукт';
            if ($quantity < 1) $errors['quantity'] = 'Количество не менее 1';
            if ($total <= 0) $errors['total'] = 'Некорректная сумма';
            if (!$consent) $errors['consent'] = 'Необходимо согласие на обработку данных';

            if (!empty($errors)) {
                http_response_code(400);
                echo json_encode(['errors' => $errors]);
                exit;
            }

            $stmt = $pdo->prepare("SELECT user_id FROM orders WHERE id = ?");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            if (!$order || $order['user_id'] != $userId) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
                exit;
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE orders SET product_type=?, quantity=?, delivery_cost=?, gift_wrap=?, organic_cert=?, total_price=? WHERE id=?");
            $stmt->execute([$product, $quantity, $delivery, $gift, $organic, $total, $orderId]);
            $stmt = $pdo->prepare("UPDATE users SET name=?, phone=?, email=?, message=?, consent=? WHERE id=?");
            $stmt->execute([$name, $phone, $email, $message, $consent, $userId]);
            $pdo->commit();
            echo json_encode(['status' => 'updated']);
        } catch (Exception $e) {
            if (isset($pdo)) $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

     // Получение списка заказов
    if ($route === 'orders' && $method === 'GET') {
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$_SESSION['user_id']]);
        $orders = $stmt->fetchAll();
        echo json_encode($orders);
        exit;
    }

    // Получение одного заказа + данные пользователя
    if ($route === 'order' && $method === 'GET' && isset($_GET['id'])) {
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        $orderId = (int)$_GET['id'];
        $stmt = $pdo->prepare("SELECT o.*, u.name, u.phone, u.email, u.message, u.consent FROM orders o JOIN users u ON o.user_id = u.id WHERE o.id = ? AND o.user_id = ?");
        $stmt->execute([$orderId, $_SESSION['user_id']]);
        $data = $stmt->fetch();
        if ($data) {
            echo json_encode($data);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Order not found']);
        }
        exit;
    }

    // Логин
    if ($route === 'login' && $method === 'POST') {
        $login = trim($input['login'] ?? '');
        $password = $input['password'] ?? '';
        if (empty($login) || empty($password)) {
            http_response_code(400);
            echo json_encode(['error' => 'Login and password required']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE login = ?");
        $stmt->execute([$login]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['login'] = $login;
            echo json_encode(['status' => 'ok']);
        } else {
            http_response_code(401);
            echo json_encode(['error' => 'Неправильный логин или пароль!']);
        }
        exit;
    }

    // Получение профиля (для авторизованного)
    if ($route === 'profile' && $method === 'GET') {
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT name, phone, email FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if ($user) {
            echo json_encode($user);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'User not found']);
        }
        exit;
    }

    // Маршрут для отмены заказа
    if ($route === 'cancel' && $method === 'POST' && isset($_GET['id'])) {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    $orderId = (int)$_GET['id'];
    $result = cancelOrder($orderId, $_SESSION['user_id']);
    if ($result['success']) {
        echo json_encode(['status' => 'cancelled']);
    } else {
        http_response_code(400);
        echo json_encode(['error' => $result['error']]);
    }
    exit;
}


    http_response_code(404);
    echo json_encode(['error' => 'Route not found']);
    exit;
}

// Получаем данные пользователя, если авторизован, для подстановки в форму
$user_data = null;
if (isset($_SESSION['user_id'])) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT name, phone, email FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_data = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Клеш Рояль | Весёлая Ферма</title>
    <link rel="icon" href="https://img.icons8.com/color/96/000000/crab.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Comic+Neue:wght@700&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .auth-nav {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .auth-nav .btn {
            padding: 8px 16px;
            font-size: 0.9rem;
            margin-left: 10px;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
        }
        .user-info {
            background: rgba(255,255,255,0.2);
            border-radius: 30px;
            padding: 5px 15px;
            color: white;
        }
        /* Переопределение стилей модальных окон (центрирование) */
        body .modal {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100% !important;
            height: 100% !important;
            background: rgba(0,0,0,0.85) !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            z-index: 10000 !important;
            visibility: hidden !important;
            opacity: 0 !important;
            transition: all 0.3s ease !important;
            transform: none !important;
        }
        body .modal.active {
            visibility: visible !important;
            opacity: 1 !important;
        }
        body .modal .modal-card {
            background: white !important;
            border-radius: 28px !important;
            padding: 30px !important;
            max-width: 450px !important;
            width: 90% !important;
            position: relative !important;
            text-align: center !important;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3) !important;
            transform: scale(0.9) !important;
            transition: transform 0.2s !important;
            margin: 0 auto !important;
        }
        body .modal.active .modal-card {
            transform: scale(1) !important;
        }
        body .modal .close {
            position: absolute !important;
            top: 15px !important;
            right: 20px !important;
            font-size: 28px !important;
            cursor: pointer !important;
            color: #888 !important;
            background: none !important;
            border: none !important;
            line-height: 1 !important;
        }
        body .modal .close:hover {
            color: #f44336 !important;
        }
        .field-error {
            color: #f44336;
            font-size: 0.8rem;
            margin-top: 5px;
        }
        .form-group.error input,
        .form-group.error select,
        .form-group.error textarea {
            border-color: #f44336;
        }
        .option-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #f9f9f9;
            padding: 8px 15px;
            border-radius: 40px;
            cursor: pointer;
        }
        .calculator-form input,
        .calculator-form select,
        .calculator-form textarea {
            width: 100%;
            padding: 14px;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            font-family: 'Nunito', sans-serif;
            font-size: 1rem;
            transition: all 0.3s;
            background-color: white;
        }
        .calculator-form input:focus,
        .calculator-form select:focus,
        .calculator-form textarea:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(76,175,80,0.2);
        }
        .calculator-result {
            background: linear-gradient(135deg, #E8F5E9, #C8E6C9);
            border-radius: 16px;
            text-align: center;
            padding: 25px;
            margin-top: 20px;
        }
        .total-price {
            font-size: 2rem;
            font-weight: bold;
            color: #4CAF50;
        }
        .form-message {
            margin-top: 15px;
            padding: 12px;
            border-radius: 10px;
            text-align: center;
            font-weight: 600;
            display: none;
        }
        .form-message.success {
            display: block;
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .form-message.error {
            display: block;
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .btn-link {
            background: none;
            border: none;
            color: var(--primary-color);
            text-decoration: underline;
            cursor: pointer;
        }


        #submit-order {
    display: block;
    width: auto;
    margin: 20px auto 0;
    text-align: center;
}
    </style>
</head>
<body>
<header>
    <div class="video-container">
        <video autoplay muted loop playsinline>
            <source src="https://assets.codepen.io/6093409/river.mp4" type="video/mp4">
        </video>
        <div class="overlay"></div>
    </div>
    <nav>
        <a href="#" class="logo"><i class="fas fa-crow"></i> Клеш<span>Рояль</span></a>
        <ul class="nav-links">
            <li><a href="#"><i class="fas fa-home"></i> Главная</a></li>
            <li><a href="#products"><i class="fas fa-carrot"></i> Урожай</a></li>
            
            <li><a href="#gallery"><i class="fas fa-images"></i> Галерея</a></li>
            <li><a href="#order-form"><i class="fas fa-shopping-cart"></i> Заказать продукты</a></li>
        </ul>

          <div class="auth-nav">
            <?php if (isset($_SESSION['user_id'])): ?>
                
                <a href="profile.php" class="btn">👤 Личный кабинет</a>
                
            <?php else: ?>
                <button id="login-btn" class="btn">Войти</button>
            <?php endif; ?>
        </div>

        <div class="burger" id="burgerBtn">
            <div></div><div></div><div></div>
        </div>
    </nav>
    <div class="hero">
        <h1>Весёлая Ферма "Клеш Рояль"</h1>
        <p>Самые весёлые овощи и самые счастливые животные! Натуральные продукты от "звёзд" фермы.</p>
        <a href="#products" class="btn">Выбрать урожай</a>
    </div>
</header>


    <!-- ========== SECTION: НАША ПРОДУКЦИЯ ========== -->
    <section id="products" class="section">
        <div class="section-title">
            <h2>Наш звёздный урожай</h2>
            <p>Выберите самые свежие и натуральные продукты с нашей фермы</p>
        </div>
        
        <div class="models-grid">
            <!-- Карточка товара 1 -->
            <div class="model-card">
                <div class="model-img">
                    <!-- Изображение будет меняться при выборе цвета через data-color на кнопках ниже -->
                    <img src="https://vesti-k.ru/i/80/80a6d494f81fd30c3c681227854f551b.jpg" alt="Овощи" id="product-1-img">
                </div>
                <div class="model-info">
                    <h3>Овощной батальон</h3>
                    <p>Хрустящие огурцы, упругие помидоры и морковь с характером. Собраны сегодня утром!</p>
                    <div class="model-price">от 150 ₽/кг</div>
                    
                    <!-- Блок выбора "цвета" (типа овоща) -->
                    <div class="color-picker">
                        <h4>Выберите отряд:</h4>
                        <div class="color-options">
                            <!-- При клике на кнопку срабатывает JavaScript, меняющий основное изображение -->
                            <div class="color-option active" style="background-color: #ff6b6b;" data-img="https://vesti-k.ru/i/80/80a6d494f81fd30c3c681227854f551b.jpg" data-product="1" title="Помидоры"></div>
                            <div class="color-option" style="background-color: #4caf50;" data-img="https://avatars.mds.yandex.net/i?id=41e8488b1eefc56a5d0e2aa3ffcd6b37_l-5278475-images-thumbs&n=13" data-product="1" title="Огурцы"></div>
                            <div class="color-option" style="background-color: #ff9800;" data-img="https://main-cdn.sbermegamarket.ru/big2/hlr-system/115/665/071/710/252/157/100029493743b1.jpg" data-product="1" title="Морковь"></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Карточка товара 2 (аналогичная структура) -->
            <div class="model-card">
                <div class="model-img">
                    <img src="https://gov.khogov.ru/wp-content/uploads/sites/4/2025/05/photo_2025-05-20_17-49-38-1024x576.jpg" alt="Фрукты" id="product-2-img">
                </div>
                <div class="model-info">
                    <h3>Фруктовая аристократия</h3>
                    <p>Яблоки в мундирах, клубника с королевским титулом и малина с наградой.</p>
                    <div class="model-price">от 300 ₽/кг</div>
                    <div class="color-picker">
                        <h4>Выберите двор:</h4>
                        <div class="color-options">
                            <div class="color-option active" style="background-color: #e91e63;" data-img="https://gov.khogov.ru/wp-content/uploads/sites/4/2025/05/photo_2025-05-20_17-49-38-1024x576.jpg" data-product="2" title="Клубника"></div>
                            <div class="color-option" style="background-color: #8bc34a;" data-img="https://avatars.mds.yandex.net/i?id=edd7d680376976f9e9b55c15147e22be_l-9700546-images-thumbs&n=13" data-product="2" title="Яблоки"></div>
                            <div class="color-option" style="background-color: #9c27b0;" data-img="https://avatars.mds.yandex.net/i?id=396f86ddb2b0828522672795d335eacf_l-4445663-images-thumbs&n=13" data-product="2" title="Малина"></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Карточка товара 3 (аналогичная структура) -->
            <div class="model-card">
                <div class="model-img">
                    <img src="https://image.made-in-china.com/2f0j00jpfMFDmyMeoU/Custom-Logo-750ml-Round-Glass-Milk-Juice-Bottle-with-Metal-Lids.webp" alt="Молочное" id="product-3-img">
                </div>
                <div class="model-info">
                    <h3>Молочные рыцари</h3>
                    <p>Молоко от коров с гербами, творог с дворянскими титулами и сметана с печатями.</p>
                    <div class="model-price">от 200 ₽/л</div>
                    <div class="color-picker">
                        <h4>Выберите герб:</h4>
                        <div class="color-options">
                            <div class="color-option active" style="background-color: #fff9c4;" data-img="https://image.made-in-china.com/2f0j00jpfMFDmyMeoU/Custom-Logo-750ml-Round-Glass-Milk-Juice-Bottle-with-Metal-Lids.webp" data-product="3" title="Молоко"></div>
                            <div class="color-option" style="background-color: #ffecb3;" data-img="https://ir.ozone.ru/s3/multimedia-6/6359498238.jpg" data-product="3" title="Сметана"></div>
                            <div class="color-option" style="background-color: #f0f4c3;" data-img="https://main-cdn.sbermegamarket.ru/big1/hlr-system/294/117/015/111/142/8/100029565711b0.jpg" data-product="3" title="Творог"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ========== SECTION: ТАБЛИЦА СРАВНЕНИЯ ========== -->
    <section class="performance-models section">
        <div class="section-title">
            <h2>Сравнение продуктового ранга</h2>
            <p>Полезность и сезонность наших основных "трофеев" с фермы</p>
        </div>
        <!-- Таблица для сравнения характеристик -->
        <div class="table-container">
            <table class="performance-table">
                <thead>
                    <tr>
                        <th>Продукт</th>
                        <th>Сезон</th>
                        <th>Срок хранения</th>
                        <th>Главная польза</th>
                        <th>Витамины</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td>Помидоры</td><td>Июль-Сентябрь</td><td>2 недели</td><td>Улучшают зрение</td><td>A, C, K</td></tr>
                    <tr><td>Огурцы</td><td>Июнь-Август</td><td>1 неделя</td><td>Увлажнение</td><td>K, B5</td></tr>
                    <tr><td>Клубника</td><td>Май-Июль</td><td>3 дня</td><td>Антиоксиданты</td><td>C, B9</td></tr>
                    <tr><td>Молоко</td><td>Круглый год</td><td>5 дней</td><td>Кальций</td><td>B2, B12, D</td></tr>
                    <tr><td>Яйца</td><td>Круглый год</td><td>3 недели</td><td>Белок</td><td>B12, D, E</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- ========== SECTION: ГАЛЕРЕЯ ========== -->
    <section id="gallery" class="gallery-section section">
        <div class="section-title">
            <h2>Галерея фермы "Клеш Рояль"</h2>
            <p>Жизнь на нашей весёлой ферме в картинках</p>
        </div>
        
        <div class="gallery-container">
            <!-- Дорожка слайдера, которая сдвигается горизонтально -->
            <div class="gallery-slider" id="gallerySlider">
                <!-- Слайд 1 -->
                <div class="gallery-slide active">
                    <img src="https://avatars.mds.yandex.net/i?id=46be3d672908a3e4e0ac58351ad1b2f5_l-5315583-images-thumbs&n=13" alt="Поле">
                    <div class="slide-content">
                        <h3>Наши королевские поля</h3>
                        <p>Широкие, солнечные поля, где растут самые отборные овощи.</p>
                    </div>
                </div>
                <!-- Слайд 2 -->
                <div class="gallery-slide">
                    <img src="https://s0.rbk.ru/v6_top_pics/media/img/8/41/756070723593418.jpg" alt="Коровы">
                    <div class="slide-content">
                        <h3>Счастливые коровы-аристократки</h3>
                        <p>Наши коровы пасутся на зелёных лугах, давая самое вкусное молоко.</p>
                    </div>
                </div>
                <!-- Слайд 3 -->
                <div class="gallery-slide">
                    <img src="https://static.tildacdn.com/tild6263-3333-4832-a361-373763366365/__2024-02-11__144828.png" alt="Урожай">
                    <div class="slide-content">
                        <h3>Торжественный сбор урожая</h3>
                        <p>Каждый день мы собираем спелые фрукты и овощи для вашего стола.</p>
                    </div>
                </div>
            </div>
            
            <!-- Кнопки управления слайдером -->
            <div class="gallery-controls">
                <button class="gallery-btn prev-btn" id="prevBtn">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <button class="gallery-btn next-btn" id="nextBtn">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
            
            <!-- Индикаторы (точки) для навигации по слайдам -->
            <div class="gallery-dots" id="galleryDots">
                <span class="gallery-dot active" data-slide="0"></span>
                <span class="gallery-dot" data-slide="1"></span>
                <span class="gallery-dot" data-slide="2"></span>
            </div>
        </div>
    </section>

    <!-- ========== SECTION: КАЛЬКУЛЯТОР СТОИМОСТИ ========== -->
    

    <!-- ========== SECTION: БЛОГ ========== -->
    <section id="blog" class="section">
        <div class="section-title">
            <h2>Фермерский блог & Новости</h2>
            <p>Советы, новости и события с нашей фермы</p>
        </div>
        
        <!-- Панель управления блогом: фильтрация и поиск -->
        <div class="blog-controls">
            <div class="blog-categories">
                <!-- Кнопки фильтров. При клике вызывается фильтрация статей -->
                <button class="category-btn active" data-category="all">Все новости</button>
                <button class="category-btn" data-category="news">Новости</button>
                <button class="category-btn" data-category="tips">Советы</button>
                <button class="category-btn" data-category="events">События</button>
            </div>
            <div class="blog-search">
                <input type="text" id="blog-search" placeholder="Поиск по статьям...">
                <button class="search-btn" id="searchBtn">
                    <i class="fas fa-search"></i>
                </button>
            </div>
        </div>
        
        <!-- Контейнер, в который JavaScript вставит карточки статей -->
<div class="blog-grid" id="blog-grid">
    <!-- Статья 1 -->
    <article class="blog-card">
        <div class="blog-category">Советы</div>
        <div class="blog-date">20 марта 2024 г.</div>
        <h3>Как выбрать самые свежие овощи?</h3>
        <p>Несколько простых советов, которые помогут вам выбрать самые качественные и спелые овощи на рынке.</p>
    </article>
    
    <!-- Статья 2 -->
    <article class="blog-card">
        <div class="blog-category">Новости</div>
        <div class="blog-date">15 марта 2024 г.</div>
        <h3>Открытие нового тепличного комплекса</h3>
        <p>Мы рады сообщить о запуске современной теплицы, которая позволит нам выращивать овощи круглый год.</p>
    </article>
    
    <!-- Статья 3 -->
    <article class="blog-card">
        <div class="blog-category">События</div>
        <div class="blog-date">10 марта 2024 г.</div>
        <h3>Экскурсия на ферму для детей</h3>
        <p>В минувшие выходные мы провели увлекательную экскурсию для школьников, чтобы показать, как работает ферма.</p>
    </article>
</div>
        <!-- Кнопка для подгрузки дополнительных статей -->
        <div class="blog-load-more">
            <button class="btn" id="load-more-btn">
                <span>Загрузить больше статей</span>
                <div class="loading-spinner"></div>
            </button>
        </div>
    </section>

    
<section id="order-form" class="section">
    <div class="section-title">
        <h2><?= isset($_SESSION['user_id']) ? 'Оформить новый заказ' : 'Оформить заказ' ?></h2>
        <p>Заполните форму, и мы доставим продукты</p>
       <?php if (isset($_SESSION['user_id'])): ?>
    <div style="text-align: center; margin-top: 15px;">
        <a href="profile.php" class="btn"> Смотреть все свои заказы</a>
    </div>
<?php endif; ?>
    </div>

    <div class="calculator" style="max-width:800px; margin:0 auto;">
        <form id="orderForm" class="calculator-form">
            <?php if (!isset($_SESSION['user_id'])): ?>
                <!-- Показываем поля для неавторизованных -->
                <div class="form-group" id="name-group">
                    <label for="name_order">Ваше имя *</label>
                    <input type="text" id="name_order" required placeholder="Иван Петров">
                    <div class="field-error"></div>
                </div>
                <div class="form-group" id="phone-group">
                    <label for="phone_order">Телефон *</label>
                    <input type="tel" id="phone_order" required placeholder="+7 (123) 456-78-90">
                    <div class="field-error"></div>
                </div>
                <div class="form-group" id="email-group">
                    <label for="email_order">Email *</label>
                    <input type="email" id="email_order" required placeholder="example@mail.ru">
                    <div class="field-error"></div>
                </div>
            <?php else: ?>
                <!-- Скрытые поля для авторизованных (значения из БД) -->
                <input type="hidden" id="name_order" value="<?= htmlspecialchars($user_data['name'] ?? '') ?>">
                <input type="hidden" id="phone_order" value="<?= htmlspecialchars($user_data['phone'] ?? '') ?>">
                <input type="hidden" id="email_order" value="<?= htmlspecialchars($user_data['email'] ?? '') ?>">
                <div class="info-message">Вы авторизованы как <strong><?= htmlspecialchars($_SESSION['login']) ?></strong>. Ваши контактные данные будут использованы автоматически.</div>
            <?php endif; ?>

            <div class="form-group" id="product-group">
                <label for="product_order">Продукт *</label>
                <select id="product_order">
                    <option value="vegetables" data-price="150">Овощи (150 ₽/кг)</option>
                    <option value="fruits" data-price="300">Фрукты (300 ₽/кг)</option>
                    <option value="milk" data-price="200">Молочные продукты (200 ₽/л)</option>
                    <option value="honey" data-price="400">Мёд (400 ₽/бут)</option>
                    <option value="cheese" data-price="500">Сыр (500 ₽/кг)</option>
                </select>
                <div class="field-error"></div>
            </div>

            <div class="form-group" id="quantity-group">
                <label for="quantity_order">Количество (1–100):</label>
                <input type="number" id="quantity_order" min="1" max="100" value="1" step="1" required>
                <div class="field-error"></div>
            </div>

            <div class="form-group" id="delivery-group">
                <label for="delivery_order">Доставка</label>
                <select id="delivery_order">
                    <option value="0">Самовывоз (бесплатно)</option>
                    <option value="300">По городу (300 ₽)</option>
                    <option value="500">За город (500 ₽)</option>
                </select>
            </div>

            <div class="form-group full-width">
                <label>Дополнительно</label>
                <div class="options-group">
                    <label class="option-checkbox"><input type="checkbox" id="gift_order" value="200"> Подарочная упаковка (+200 ₽)</label>
                    <label class="option-checkbox"><input type="checkbox" id="organic_order" value="150"> Сертификат "Био" (+150 ₽)</label>
                </div>
            </div>

            

            <div class="form-group" id="message-group">
                <label for="message_order">Пожелания к заказу</label>
                <textarea id="message_order" rows="3"></textarea>
                <div class="field-error"></div>
            </div>

            <div class="calculator-result">
                <h3>Итоговая стоимость</h3>
                <div class="total-price" id="total_price_order">0 ₽</div>
            </div>
            <div class="form-group full-width">
                <label class="option-checkbox">
                    <span>Я даю согласие на обработку персональных данных *</span>
                    <input type="checkbox" id="consent_order" required >
                    
                </label>
                <div class="field-error" id="consent-error"></div>
            </div>
            <div style="text-align: center;">
                <button type="submit" class="btn" id="submit-order">Оформить заказ</button>
            </div>
            <div id="form-message" class="form-message"></div>
        </form>
    </div>
</section>




<div id="login-modal" class="modal">
    <div class="modal-card">
        <span class="close" id="close-login">&times;</span>
        <h3>Вход в систему</h3>
        <form id="loginForm">
            <div class="form-group"><label>Логин</label><input type="text" id="login-username" required></div>
            <div class="form-group"><label>Пароль</label><input type="password" id="login-password" required></div>
            <button type="submit" class="btn">Войти</button>
            <div id="login-error" style="color:red; margin-top:10px;"></div>
        </form>
    </div>
</div>

<div id="creds-modal" class="modal">
    <div class="modal-card">
        <span class="close" id="close-creds">&times;</span>
        <h3>Ваши данные для входа</h3>
        <p><strong>Логин:</strong> <span id="new-login"></span></p>
        <p><strong>Пароль:</strong> <span id="new-password"></span></p>
        <p>Сохраните их! Вы уже авторизованы.</p>
        <button class="btn" id="close-creds-btn">Закрыть</button>
    </div>
</div>


<footer>
     <div class="footer-content">
            <div class="footer-logo"><i class="fas fa-crown"></i> Клеш<span>Рояль</span></div>
            <ul class="footer-links">
                <li><a href="#">Главная</a></li>
                <li><a href="#products">Урожай</a></li>
                <li><a href="#gallery">Галерея</a></li>
                <li><a href="#order-form">Заказать продукты</a></li>
            </ul>
            <div class="quote-section">
                <p class="inspiration-quote">
                   © Эдуард Мхитарян
                </p>
            </div>
            <div class="social-links">
                <a href="#"><i class="fab fa-vk"></i></a>
                <a href="#"><i class="fab fa-telegram"></i></a>
                <a href="#"><i class="fab fa-instagram"></i></a>
            </div>
            <div class="copyright">© 2023 Весёлая Ферма "Клеш Рояль".</div>
        </div>
</footer>
 <script src="script.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Калькулятор
    const productSelect = document.getElementById('product_order');
    const quantityInput = document.getElementById('quantity_order');
    const deliverySelect = document.getElementById('delivery_order');
    const giftChk = document.getElementById('gift_order');
    const organicChk = document.getElementById('organic_order');
    const totalSpan = document.getElementById('total_price_order');

    function calcTotal() {
        if (!productSelect || !quantityInput || !deliverySelect || !totalSpan) return;
        let price = parseInt(productSelect.options[productSelect.selectedIndex].dataset.price);
        let qty = parseInt(quantityInput.value);
        if (isNaN(qty) || qty < 1) qty = 1;
        let delivery = parseInt(deliverySelect.value);
        let extra = (giftChk.checked ? 200 : 0) + (organicChk.checked ? 150 : 0);
        let total = (price * qty) + delivery + extra;
        totalSpan.innerText = total + ' ₽';
    }
    if (productSelect) {
        productSelect.addEventListener('change', calcTotal);
        quantityInput.addEventListener('input', calcTotal);
        deliverySelect.addEventListener('change', calcTotal);
        if (giftChk) giftChk.addEventListener('change', calcTotal);
        if (organicChk) organicChk.addEventListener('change', calcTotal);
        calcTotal();
    }

    // Авторизация
    const loginBtn = document.getElementById('login-btn');
    const loginModal = document.getElementById('login-modal');
    const closeLogin = document.getElementById('close-login');
    if (loginBtn) loginBtn.onclick = () => loginModal.classList.add('active');
    if (closeLogin) closeLogin.onclick = () => loginModal.classList.remove('active');
    window.onclick = (e) => { if (e.target === loginModal) loginModal.classList.remove('active'); };

    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
        loginForm.onsubmit = async (e) => {
            e.preventDefault();
            const login = document.getElementById('login-username').value;
            const password = document.getElementById('login-password').value;
            const errorDiv = document.getElementById('login-error');
            try {
                const res = await fetch('index.php?route=login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ login, password })
                });
                const data = await res.json();
                if (res.ok && data.status === 'ok') location.reload();
                else errorDiv.innerText = data.error || 'Ошибка входа';
            } catch(err) { errorDiv.innerText = 'Ошибка сети'; }
        };
    }

    // Отправка формы
    const orderForm = document.getElementById('orderForm');
    const messageDiv = document.getElementById('form-message');
    if (orderForm) {
        orderForm.onsubmit = async (e) => {
    e.preventDefault();
    const name = document.getElementById('name_order').value.trim();
    const phone = document.getElementById('phone_order').value.trim();
    const email = document.getElementById('email_order').value.trim();
    const message = document.getElementById('message_order').value.trim();
    const consent = document.getElementById('consent_order').checked;
    const product = productSelect.value;
    const quantity = parseInt(quantityInput.value);
    const delivery = parseInt(deliverySelect.value);
    const gift = giftChk.checked;
    const organic = organicChk.checked;
    const total = parseInt(totalSpan.innerText);

    document.querySelectorAll('.form-group').forEach(g => g.classList.remove('error'));
    document.querySelectorAll('.field-error').forEach(e => e.innerText = '');

    const data = { name, phone, email, message, consent, product, quantity, delivery, gift, organic, total };
    try {
        const res = await fetch('index.php?route=order', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const result = await res.json();
        if (res.ok && result.status === 'created') {
            messageDiv.innerText = 'Заказ оформлен!';
            messageDiv.className = 'form-message success';
            if (result.login && result.password) {
                document.getElementById('new-login').innerText = result.login;
                document.getElementById('new-password').innerText = result.password;
                const credsModal = document.getElementById('creds-modal');
                credsModal.classList.add('active');
                // Удаляем любые старые таймеры, которые могли закрыть модалку
                if (window._credsTimeout) clearTimeout(window._credsTimeout);
            }
            orderForm.reset();
            quantityInput.value = 1;
            calcTotal();
        } else {
            if (result.errors) {
                for (const [field, err] of Object.entries(result.errors)) {
                    if (field === 'consent') {
                        document.getElementById('consent-error').innerText = err;
                    } else {
                        const group = document.getElementById(`${field}-group`);
                        if (group) {
                            group.classList.add('error');
                            group.querySelector('.field-error').innerText = err;
                        }
                    }
                }
            } else messageDiv.innerText = result.error || 'Ошибка';
        }
    } catch(err) { messageDiv.innerText = 'Ошибка сети'; }
    setTimeout(() => messageDiv.innerText = '', 3000);
};
    }

    // Закрытие модалки с данными
    const credsModal = document.getElementById('creds-modal');
    const closeCreds = document.getElementById('close-creds');
    const closeCredsBtn = document.getElementById('close-creds-btn');
    function closeModal() { credsModal.classList.remove('active'); }
    if (closeCreds) closeCreds.onclick = closeModal;
    if (closeCredsBtn) closeCredsBtn.onclick = closeModal;
    
});
</script>
</body>
</html>