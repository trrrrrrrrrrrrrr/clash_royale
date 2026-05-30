<?php
session_start();
require_once 'db.php';

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
            $gift = isset($input['gift']) ? 1 : 0;
            $organic = isset($input['organic']) ? 1 : 0;
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

    // Обновление заказа (аналогично добавить поле consent)
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

    // Остальные маршруты (GET orders, GET order, POST login) без изменений
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
            echo json_encode(['error' => 'Invalid credentials']);
        }
        exit;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Route not found']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Клеш Рояль | Весёлая Ферма</title>
    <link href="https://fonts.googleapis.com/css2?family=Comic+Neue:wght@700&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        /* Дополнительные стили для авторизации и сообщений */
        .auth-buttons {
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 20;
        }
        .auth-buttons .btn {
            padding: 8px 16px;
            font-size: 0.9rem;
            margin-left: 10px;
        }
        .user-info {
            background: rgba(255,255,255,0.9);
            border-radius: 30px;
            padding: 5px 15px;
            display: inline-block;
            color: #333;
        }
        .field-error {
            color: #f44336;
            font-size: 0.8rem;
            margin-top: 5px;
        }
        .form-group.error input, .form-group.error textarea {
            border-color: #f44336;
        }
         .orders-list { margin-top: 30px; background: white; border-radius: 16px; padding: 20px; }
        .order-item { border-bottom: 1px solid #eee; padding: 15px; cursor: pointer; }
        .order-item:hover { background: #f9f9f9; }

         .calculator-form input, .calculator-form select, .calculator-form textarea {
            width: 100%;
            padding: 14px;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            font-family: 'Nunito', sans-serif;
            font-size: 1rem;
            transition: all 0.3s;
            background-color: white;
        }
        .calculator-form input:focus, .calculator-form select:focus, .calculator-form textarea:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(76,175,80,0.2);
        }
        .calculator-form label {
            font-weight: 600;
            margin-bottom: 6px;
            display: block;
            color: var(--dark-color);
        }
        .options-group {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            margin-top: 10px;
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
        .calculator-result {
            background: linear-gradient(135deg, #E8F5E9, #C8E6C9);
            border-radius: 16px;
        }

          .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            visibility: hidden;
            opacity: 0;
            transition: all 0.3s ease;
        }
        .modal.active {
            visibility: visible;
            opacity: 1;
        }
        .modal-card {
            background: white;
            border-radius: 28px;
            padding: 30px;
            max-width: 450px;
            width: 90%;
            position: relative;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
            transform: scale(0.9);
            transition: transform 0.2s;
        }
        .modal.active .modal-card {
            transform: scale(1);
        }
        .modal-card .close {
            position: absolute;
            top: 15px;
            right: 20px;
            font-size: 28px;
            cursor: pointer;
            color: #888;
        }
        .modal-card .close:hover {
            color: #f44336;
        }


         .profile-link {
            text-align: center;
            margin-top: 30px;
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
            <li><a href="#contact"><i class="fas fa-address-book"></i> Контакты</a></li>
        </ul>
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
                    <img src="https://avatars.mds.yandex.net/i?id=262fce599ffaab67a84dcbdb7d5d8ba920b6efd7-4080301-images-thumbs&n=13" alt="Овощи" id="product-1-img">
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
                    <img src="https://static.mk.ru/upload/entities/2024/11/25/09/articles/facebookPicture/72/b3/63/73/11b4f3ffd24d2a3e9764a41f1cfd6640.jpg" alt="Фрукты" id="product-2-img">
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
                    <img src="https://edaprof.ru/image/catalog/statii/tekhnologiya-proizvodstva-moloka-i-molochnoj-produkcii/moloko.jpg" alt="Молочное" id="product-3-img">
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
    <div class="section-title"><h2>Оформить заказ</h2><p>Заполните форму, и мы доставим продукты</p></div>

    <div id="auth-status" style="text-align: right; margin-bottom: 20px;">
        <?php if (isset($_SESSION['user_id'])): ?>
            <span class="user-info">Привет, <?= htmlspecialchars($_SESSION['login']) ?></span>
            <a href="profile.php" class="btn" style="margin-left: 10px;">👤 Личный кабинет</a>
            <a href="logout.php" class="btn" style="margin-left: 10px;">Выйти</a>
        <?php else: ?>
            <button id="login-btn" class="btn">Войти</button>
        <?php endif; ?>
    </div>

    <div class="calculator" style="max-width:800px; margin:0 auto;">
        <form id="orderForm" class="calculator-form">
            <!-- Поля (ID с суффиксом _order) -->
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
                <label for="quantity_order">Количество: <span id="quantityVal_order">1</span></label>
                <input type="range" id="quantity_order" min="1" max="20" value="1">
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
            <!-- Чекбокс согласия (обязательный) -->
            <div class="form-group full-width">
                <label class="option-checkbox">
                    <input type="checkbox" id="consent_order">
                    <span>Я даю согласие на обработку персональных данных *</span>
                </label>
                <div class="field-error" id="consent-error"></div>
            </div>
            <div class="form-group" id="message-group">
                <label for="message_order">Пожелания к заказу</label>
                <textarea id="message_order" rows="3" placeholder="Например: без лука, доставка к 18:00"></textarea>
                <div class="field-error"></div>
            </div>
            <div class="calculator-result">
                <h3>Итоговая стоимость</h3>
                <div class="total-price" id="total_price_order">0 ₽</div>
            </div>
            <button type="submit" class="btn" id="submit-order">Оформить заказ</button>
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

<!-- Модальное окно с логином/паролем -->
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
                <li><a href="#calculator">Калькулятор</a></li>
                <li><a href="#gallery">Галерея</a></li>
                <li><a href="#contact">Контакты</a></li>
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
    const quantitySlider = document.getElementById('quantity_order');
    const quantityVal = document.getElementById('quantityVal_order');
    const deliverySelect = document.getElementById('delivery_order');
    const giftChk = document.getElementById('gift_order');
    const organicChk = document.getElementById('organic_order');
    const totalSpan = document.getElementById('total_price_order');

    function calcTotal() {
        if (!productSelect || !quantitySlider || !deliverySelect || !totalSpan) return;
        let price = parseInt(productSelect.options[productSelect.selectedIndex].dataset.price);
        let qty = parseInt(quantitySlider.value);
        let delivery = parseInt(deliverySelect.value);
        let extra = (giftChk.checked ? 200 : 0) + (organicChk.checked ? 150 : 0);
        let total = (price * qty) + delivery + extra;
        totalSpan.innerText = total + ' ₽';
        if (quantityVal) quantityVal.innerText = qty;
    }
    if (productSelect) {
        productSelect.addEventListener('change', calcTotal);
        quantitySlider.addEventListener('input', calcTotal);
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
            const quantity = quantitySlider.value;
            const delivery = deliverySelect.value;
            const gift = giftChk.checked;
            const organic = organicChk.checked;
            const total = parseInt(totalSpan.innerText);

            // Очистка ошибок
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
                        document.getElementById('creds-modal').classList.add('active');
                    }
                    orderForm.reset();
                    quantitySlider.value = 1;
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
    window.onclick = (e) => { if (e.target === credsModal) closeModal(); };
});
</script>
</body>
</html>