<?php
session_start();
require_once 'db.php';

$route = $_GET['route'] ?? null;
if ($route) {
    header('Content-Type: application/json');
    $method = $_SERVER['REQUEST_METHOD'];
    // Эмуляция PUT через POST + _method
    if ($method === 'POST' && isset($_GET['_method'])) {
        $method = strtoupper($_GET['_method']);
    }
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input && ($method === 'POST' || $method === 'PUT')) {
        $input = $_POST;
    }
    $pdo = getDB();

    // Создание заказа (неавторизованный -> регистрация)
    if ($route === 'order' && $method === 'POST') {
        $name = trim($input['name'] ?? '');
        $email = trim($input['email'] ?? '');
        $product = trim($input['product'] ?? '');
        $quantity = (int)($input['quantity'] ?? 0);
        $delivery = (int)($input['delivery'] ?? 0);
        $gift = isset($input['gift']) ? 1 : 0;
        $organic = isset($input['organic']) ? 1 : 0;
        $total = (int)($input['total'] ?? 0);

        $errors = [];
        if (empty($name)) $errors['name'] = 'Имя обязательно';
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Некорректный email';
        if (empty($product)) $errors['product'] = 'Выберите продукт';
        if ($quantity < 1) $errors['quantity'] = 'Количество должно быть не менее 1';
        if ($total <= 0) $errors['total'] = 'Некорректная сумма';

        if (!empty($errors)) {
            http_response_code(400);
            echo json_encode(['errors' => $errors]);
            exit;
        }

        $pdo->beginTransaction();
        try {
            if (isset($_SESSION['user_id'])) {
                $userId = $_SESSION['user_id'];
                $login = null;
                $plainPassword = null;
            } else {
                $login = generateUniqueLogin($pdo);
                $plainPassword = generatePassword();
                $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (login, password_hash, name, email) VALUES (?, ?, ?, ?)");
                $stmt->execute([$login, $hash, $name, $email]);
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
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // Обновление заказа (только авторизованный)
    if ($route === 'order' && $method === 'PUT' && isset($_GET['id'])) {
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

        $errors = [];
        if (empty($product)) $errors['product'] = 'Выберите продукт';
        if ($quantity < 1) $errors['quantity'] = 'Количество должно быть не менее 1';
        if ($total <= 0) $errors['total'] = 'Некорректная сумма';

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

        $stmt = $pdo->prepare("UPDATE orders SET product_type=?, quantity=?, delivery_cost=?, gift_wrap=?, organic_cert=?, total_price=? WHERE id=?");
        $stmt->execute([$product, $quantity, $delivery, $gift, $organic, $total, $orderId]);
        echo json_encode(['status' => 'updated']);
        exit;
    }

    // Получение списка заказов пользователя
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

    // Получение одного заказа
    if ($route === 'order' && $method === 'GET' && isset($_GET['id'])) {
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        $orderId = (int)$_GET['id'];
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
        $stmt->execute([$orderId, $_SESSION['user_id']]);
        $order = $stmt->fetch();
        if ($order) {
            echo json_encode($order);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Order not found']);
        }
        exit;
    }

    // Вход
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

// Если не API – отдаём HTML-страницу
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
        .credentials-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            visibility: hidden;
            opacity: 0;
            transition: 0.3s;
        }
        .credentials-modal.active {
            visibility: visible;
            opacity: 1;
        }
        .credentials-card {
            background: white;
            border-radius: 24px;
            padding: 30px;
            max-width: 400px;
            text-align: center;
            position: relative;
        }
        .credentials-card .close {
            position: absolute;
            top: 15px;
            right: 20px;
            font-size: 28px;
            cursor: pointer;
        }
        .field-error {
            color: #f44336;
            font-size: 0.8rem;
            margin-top: 5px;
        }
        .form-group.error input, .form-group.error textarea {
            border-color: #f44336;
        }

  .modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); display: flex; align-items: center; justify-content: center; z-index: 2000; visibility: hidden; opacity: 0; transition: 0.3s; }
        .modal.active { visibility: visible; opacity: 1; }
        .modal-card { background: white; border-radius: 24px; padding: 30px; max-width: 450px; width: 90%; position: relative; }
        .modal-card .close { position: absolute; top: 15px; right: 20px; font-size: 28px; cursor: pointer; }
         .orders-list { margin-top: 30px; background: white; border-radius: 16px; padding: 20px; }
        .order-item { border-bottom: 1px solid #eee; padding: 15px; cursor: pointer; }
        .order-item:hover { background: #f9f9f9; }
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
            <li><a href="#calculator"><i class="fas fa-calculator"></i> Калькулятор</a></li>
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
    <section id="calculator" class="section">
        <div class="section-title">
            <h2>Калькулятор заказа</h2>
            <p>Рассчитайте стоимость вашей корзины с фермерскими продуктами</p>
        </div>
        
        <div class="calculator">
            <!-- Форма калькулятора. Событие 'input' на элементах вызывает пересчёт -->
            <form class="calculator-form" id="price-calculator">
                <div class="form-group">
                    <label for="product">Тип продукта</label>
                    <select id="product" name="product">
                        <option value="150">Овощи (150 ₽/кг)</option>
                        <option value="300">Фрукты (300 ₽/кг)</option>
                        <option value="200">Молоко (200 ₽/л)</option>
                        <option value="400">Мёд (400 ₽/бут.)</option>
                        <option value="500">Сыр (500 ₽/кг)</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="quantity">Количество: <span id="quantityValue">5</span> кг/л</label>
                    <!-- Ползунок для выбора количества -->
                    <input type="range" id="quantity" name="quantity" min="1" max="20" value="5">
                </div>
                
                <div class="form-group">
                    <label for="delivery">Доставка</label>
                    <select id="delivery" name="delivery">
                        <option value="0">Самовывоз (бесплатно)</option>
                        <option value="300">По городу (300 ₽)</option>
                        <option value="500">За город (500 ₽)</option>
                    </select>
                </div>
                
                <div class="form-group full-width">
                    <label>Дополнительно</label>
                    <div class="options-group">
                        <div class="option-checkbox">
                            <input type="checkbox" id="gift" name="gift" value="200">
                            <label for="gift">Подарочная упаковка (+200 ₽)</label>
                        </div>
                        <div class="option-checkbox">
                            <input type="checkbox" id="organic" name="organic" value="150">
                            <label for="organic">Сертификат "Био" (+150 ₽)</label>
                        </div>
                    </div>
                </div>
                
                <!-- Блок с результатом вычислений -->
                <div class="calculator-result">
                    <h3>Итоговая стоимость</h3>
                    <div class="total-price" id="total-price">1 050 ₽</div>
                    <p class="hint">(5 кг овощей × 150 ₽ + доставка 0 ₽)</p>
                </div>
            </form>
        </div>
    </section>

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

    <div id="auth-status" class="auth-buttons">
        <?php if (isset($_SESSION['user_id'])): ?>
            <span class="user-info">Привет, <?= htmlspecialchars($_SESSION['login']) ?></span>
            <a href="logout.php" class="btn">Выйти</a>
        <?php else: ?>
            <button id="login-btn" class="btn">Войти</button>
        <?php endif; ?>
    </div>

    <div class="calculator" style="max-width:800px; margin:0 auto;">
        <form id="orderForm" class="calculator-form">
            <div class="form-group" id="name-group">
                <label for="name">Ваше имя *</label>
                <input type="text" id="name" name="name" required placeholder="Иван Петров">
                <div class="field-error"></div>
            </div>
            <div class="form-group" id="email-group">
                <label for="email">Email *</label>
                <input type="email" id="email" name="email" required placeholder="example@mail.ru">
                <div class="field-error"></div>
            </div>
            <div class="form-group" id="product-group">
                <label for="product">Продукт *</label>
                <select id="product" name="product">
                    <option value="vegetables" data-price="150">Овощи (150 ₽/кг)</option>
                    <option value="fruits" data-price="300">Фрукты (300 ₽/кг)</option>
                    <option value="milk" data-price="200">Молочные продукты (200 ₽/л)</option>
                    <option value="honey" data-price="400">Мёд (400 ₽/бут)</option>
                    <option value="cheese" data-price="500">Сыр (500 ₽/кг)</option>
                </select>
                <div class="field-error"></div>
            </div>
            <div class="form-group" id="quantity-group">
                <label for="quantity">Количество: <span id="quantityVal">1</span></label>
                <input type="range" id="quantity" min="1" max="20" value="1">
                <div class="field-error"></div>
            </div>
            <div class="form-group" id="delivery-group">
                <label for="delivery">Доставка</label>
                <select id="delivery">
                    <option value="0">Самовывоз (бесплатно)</option>
                    <option value="300">По городу (300 ₽)</option>
                    <option value="500">За город (500 ₽)</option>
                </select>
                <div class="field-error"></div>
            </div>
            <div class="form-group full-width">
                <label>Дополнительно</label>
                <div class="options-group">
                    <label class="option-checkbox"><input type="checkbox" id="gift" value="200"> Подарочная упаковка (+200 ₽)</label>
                    <label class="option-checkbox"><input type="checkbox" id="organic" value="150"> Сертификат "Био" (+150 ₽)</label>
                </div>
            </div>
            <div class="calculator-result">
                <h3>Итоговая стоимость</h3>
                <div class="total-price" id="total-price">0 ₽</div>
            </div>
            <button type="submit" class="btn">Оформить заказ</button>
            <div id="form-message" class="form-message"></div>
        </form>
    </div>

    <!-- Список заказов для авторизованного -->
    <?php if (isset($_SESSION['user_id'])): ?>
    <div class="orders-list" id="orders-container">
        <h3>Мои заказы</h3>
        <div id="orders-list">Загрузка...</div>
    </div>
    <?php endif; ?>
</section>

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


 <script src="script.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('contact-form');
    const messageDiv = document.getElementById('form-message');
    const loginModal = document.getElementById('login-modal');
    const credsModal = document.getElementById('creds-modal');
    const loginBtn = document.getElementById('login-btn');
    const closeLogin = document.getElementById('close-login-modal');
    const closeCreds = document.getElementById('close-creds-modal');
    const closeCredsBtn = document.getElementById('close-creds-btn');

    // Открытие модалки входа
    if (loginBtn) loginBtn.addEventListener('click', () => loginModal.classList.add('active'));
    function closeLoginModal() { loginModal.classList.remove('active'); }
    if (closeLogin) closeLogin.addEventListener('click', closeLoginModal);
    window.addEventListener('click', (e) => { if (e.target === loginModal) closeLoginModal(); });

    // Логин
    const loginForm = document.getElementById('login-form');
    if (loginForm) {
        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const login = document.getElementById('login-login').value;
            const password = document.getElementById('login-password').value;
            const errorDiv = document.getElementById('login-error');
            try {
                const res = await fetch('index.php?route=login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ login, password })
                });
                const data = await res.json();
                if (res.ok && data.status === 'ok') {
                    window.location.reload();
                } else {
                    errorDiv.textContent = data.error || 'Ошибка входа';
                }
            } catch (err) {
                errorDiv.textContent = 'Сетевая ошибка';
            }
        });
    }

    // Загрузка профиля для авторизованного пользователя
    <?php if (isset($_SESSION['user_id'])): ?>
        fetch('index.php?route=profile')
            .then(res => res.json())
            .then(data => {
                if (data.name) document.getElementById('name').value = data.name;
                if (data.email) document.getElementById('email').value = data.email;
                if (data.message) document.getElementById('message').value = data.message;
            })
            .catch(console.error);
    <?php endif; ?>

    // Отправка формы (POST или PUT)
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const name = document.getElementById('name').value.trim();
        const email = document.getElementById('email').value.trim();
        const message = document.getElementById('message').value.trim();

        document.querySelectorAll('.form-group').forEach(g => g.classList.remove('error'));
        document.querySelectorAll('.field-error').forEach(e => e.textContent = '');

        const data = { name, email, message };
        const isLoggedIn = <?= isset($_SESSION['user_id']) ? 'true' : 'false' ?>;
        const url = isLoggedIn ? 'index.php?route=contact&_method=PUT' : 'index.php?route=contact';
        const method = 'POST';

        try {
            const res = await fetch(url, {
                method: method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            const result = await res.json();
            if (res.ok) {
                if (result.status === 'created') {
                    document.getElementById('new-login').textContent = result.login;
                    document.getElementById('new-password').textContent = result.password;
                    credsModal.classList.add('active');
                }
                messageDiv.textContent = isLoggedIn ? 'Данные обновлены!' : 'Заявка отправлена!';
                messageDiv.className = 'form-message success';
                setTimeout(() => messageDiv.className = 'form-message', 3000);
            } else {
                if (result.errors) {
                    for (const [field, error] of Object.entries(result.errors)) {
                        const group = document.getElementById(`${field}-group`);
                        if (group) {
                            group.classList.add('error');
                            group.querySelector('.field-error').textContent = error;
                        }
                    }
                } else {
                    messageDiv.textContent = result.error || 'Ошибка';
                    messageDiv.className = 'form-message error';
                }
            }
        } catch (err) {
            messageDiv.textContent = 'Ошибка сети. Проверьте соединение.';
            messageDiv.className = 'form-message error';
        }
    });

    // Закрытие модалки с данными
    function closeCredsModal() { credsModal.classList.remove('active'); }
    if (closeCreds) closeCreds.addEventListener('click', closeCredsModal);
    if (closeCredsBtn) closeCredsBtn.addEventListener('click', closeCredsModal);
    window.addEventListener('click', (e) => { if (e.target === credsModal) closeCredsModal(); });
});
</script>
</body>
</html>