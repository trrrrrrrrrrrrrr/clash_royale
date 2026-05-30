<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$pdo = getDB();
$userId = $_SESSION['user_id'];

// Заказы пользователя
$stmt = $pdo->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$userId]);
$orders = $stmt->fetchAll();

// Данные пользователя
$stmtUser = $pdo->prepare("SELECT name, phone, email, login FROM users WHERE id = ?");
$stmtUser->execute([$userId]);
$user = $stmtUser->fetch();

$updated = isset($_GET['updated']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Личный кабинет | Клеш Рояль</title>
    <link rel="icon" href="https://img.icons8.com/color/96/000000/crab.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Comic+Neue:wght@700&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        body { background: #f5f5f5; font-family: 'Nunito', sans-serif; }
        .profile-container {
            max-width: 1200px;
            margin: 40px auto;
            background: white;
            border-radius: 28px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .user-info {
            background: #E8F5E9;
            padding: 20px;
            border-radius: 20px;
            margin-bottom: 30px;
        }
        .orders-table {
            width: 100%;
            border-collapse: collapse;
        }
        .orders-table th, .orders-table td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: left;
            vertical-align: middle;
        }
        .orders-table th {
            background: #4CAF50;
            color: white;
        }
        .edit-btn, .cancel-btn {
            background: #FF9800;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 0.85rem;
            margin-right: 5px;
        }
        .cancel-btn {
            background: #f44336;
        }
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.85);
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
            max-width: 600px;
            width: 90%;
            position: relative;
            text-align: left;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
        }
        .modal-card .close {
            position: absolute;
            top: 15px;
            right: 20px;
            font-size: 28px;
            cursor: pointer;
            color: #888;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
            color: #333;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 40px;
            font-family: 'Nunito', sans-serif;
            font-size: 1rem;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: #4CAF50;
            outline: none;
            box-shadow: 0 0 0 3px rgba(76,175,80,0.2);
        }
        .btn {
            background: linear-gradient(135deg, #4CAF50, #388E3C);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 40px;
            cursor: pointer;
            font-weight: 600;
        }
        .logout-btn {
            background: #f44336;
            margin-left: 10px;
        }
        .toggle-group {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        .toggle-btn {
            background: #f0f0f0;
            border: 1px solid #ccc;
            padding: 8px 20px;
            border-radius: 40px;
            cursor: pointer;
            transition: 0.2s;
        }
        .toggle-btn.active {
            background: #4CAF50;
            color: white;
            border-color: #4CAF50;
        }
        .confirm-buttons {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 20px;
        }
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 10px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
<div class="profile-container">
    <h1>👤 Личный кабинет</h1>
    <?php if ($updated): ?>
        <div class="success-message">✅ Заказ успешно обновлён!</div>
    <?php endif; ?>
    <div class="user-info">
        <p><strong>Логин:</strong> <?= htmlspecialchars($user['login']) ?></p>
        <p><strong>Имя:</strong> <?= htmlspecialchars($user['name']) ?></p>
        <p><strong>Телефон:</strong> <?= htmlspecialchars($user['phone']) ?></p>
        <p><strong>Email:</strong> <?= htmlspecialchars($user['email']) ?></p>
        <div style="margin-top: 15px;">
            <a href="index.php" class="btn">← Вернуться на главную</a>
            <button id="logout-btn" class="btn logout-btn">Выйти</button>
        </div>
    </div>

    <h2>Мои заказы</h2>
    <?php if (empty($orders)): ?>
        <p>У вас пока нет заказов. <a href="index.php#order-form">Сделать первый заказ</a></p>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="orders-table">
                <thead>
                    <tr><th>ID</th><th>Товар</th><th>Кол-во</th><th>Доставка</th><th>Упаковка</th><th>Био</th><th>Сумма</th><th>Статус</th><th>Действия</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order):
                        $productName = match($order['product_type']) {
                            'vegetables' => 'Овощи', 'fruits' => 'Фрукты', 'milk' => 'Молочные продукты',
                            'honey' => 'Мёд', 'cheese' => 'Сыр', default => $order['product_type']
                        };
                        $statusName = match($order['status']) {
                            'new' => 'Новый', 'processed' => 'В обработке',
                            'completed' => 'Выполнен', 'cancelled' => 'Отменён', default => $order['status']
                        };
                        $isNew = $order['status'] === 'new';
                    ?>
                    <tr>
                        <td><?= $order['id'] ?></td>
                        <td><?= $productName ?></td>
                        <td><?= $order['quantity'] ?></td>
                        <td><?= $order['delivery_cost'] ?> ₽</td>
                        <td><?= $order['gift_wrap'] ? '✅' : '❌' ?></td>
                        <td><?= $order['organic_cert'] ? '✅' : '❌' ?></td>
                        <td><?= $order['total_price'] ?> ₽</td>
                        <td><?= $statusName ?></td>
                        <td>
                            <?php if ($isNew): ?>
                                <button class="edit-btn" data-id="<?= $order['id'] ?>"> Редактировать</button>
                                <button class="cancel-btn" data-id="<?= $order['id'] ?>"> Отменить</button>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Модалка редактирования -->
<div id="edit-modal" class="modal">
    <div class="modal-card">
        <span class="close" id="close-edit">&times;</span>
        <h3>Редактирование заказа</h3>
        <form id="editForm" method="post" action="update_order.php">
            <input type="hidden" name="order_id" id="edit-order-id">
            <div class="form-group">
                <label>Продукт</label>
                <select name="product" id="edit-product"></select>
            </div>
            <div class="form-group">
                <label>Количество</label>
                <input type="number" name="quantity" id="edit-quantity" min="1" max="100" required>
            </div>
            <div class="form-group">
                <label>Доставка</label>
                <select name="delivery" id="edit-delivery"></select>
            </div>
            <div class="form-group">
                <label>Пожелания</label>
                <textarea name="message" id="edit-message" rows="3"></textarea>
            </div>
            <div class="form-group">
                <label>Подарочная упаковка (+200 ₽)</label>
                <div class="toggle-group">
                    <button type="button" class="toggle-btn" data-opt="gift" data-value="1">Вкл</button>
                    <button type="button" class="toggle-btn" data-opt="gift" data-value="0">Выкл</button>
                </div>
                <input type="hidden" name="gift" id="edit-gift-hidden" value="0">
            </div>
            <div class="form-group">
                <label>Сертификат "Био" (+150 ₽)</label>
                <div class="toggle-group">
                    <button type="button" class="toggle-btn" data-opt="organic" data-value="1">Вкл</button>
                    <button type="button" class="toggle-btn" data-opt="organic" data-value="0">Выкл</button>
                </div>
                <input type="hidden" name="organic" id="edit-organic-hidden" value="0">
            </div>
            <button type="submit" class="btn">Сохранить изменения</button>
        </form>
    </div>
</div>

<!-- Модалка выхода -->
<div id="confirm-logout-modal" class="modal">
    <div class="modal-card">
        <span class="close" id="close-logout-confirm">&times;</span>
        <h3>Подтверждение выхода</h3>
        <p>Вы уверены, что хотите выйти из системы?</p>
        <div class="confirm-buttons">
            <button id="confirm-logout-yes" class="btn">Да, выйти</button>
            <button id="confirm-logout-no" class="btn" style="background:#999;">Отмена</button>
        </div>
    </div>
</div>

<!-- Модалка информации (для отмены) -->
<div id="info-modal" class="modal">
    <div class="modal-card">
        <span class="close" id="close-info">&times;</span>
        <h3>Информация</h3>
        <p id="info-message-text"></p>
        <button id="info-ok" class="btn">Закрыть</button>
    </div>
</div>

<!-- Модальное окно подтверждения отмены заказа -->
<div id="confirm-cancel-modal" class="modal">
    <div class="modal-card">
        <span class="close" id="close-cancel-confirm">&times;</span>
        <h3>Подтверждение отмены</h3>
        <p>Вы уверены, что хотите отменить этот заказ? Это действие нельзя отменить.</p>
        <div class="confirm-buttons">
            <button id="confirm-cancel-yes" class="btn">Да, отменить</button>
            <button id="confirm-cancel-no" class="btn" style="background:#999;">Нет</button>
        </div>
    </div>
</div>


<script>
const logoutBtn = document.getElementById('logout-btn');
    const confirmLogoutModal = document.getElementById('confirm-logout-modal');
    const closeLogout = document.getElementById('close-logout-confirm');
    const confirmLogoutYes = document.getElementById('confirm-logout-yes');
    const confirmLogoutNo = document.getElementById('confirm-logout-no');
    if (logoutBtn) logoutBtn.onclick = () => confirmLogoutModal.classList.add('active');
    function closeLogoutModal() { confirmLogoutModal.classList.remove('active'); }
    if (closeLogout) closeLogout.onclick = closeLogoutModal;
    if (confirmLogoutNo) confirmLogoutNo.onclick = closeLogoutModal;
    if (confirmLogoutYes) confirmLogoutYes.onclick = () => { window.location.href = 'logout.php'; };
    window.onclick = (e) => { if (e.target === confirmLogoutModal) closeLogoutModal(); };

    // ------ Инфо-модалка (уведомления) ------
    const infoModal = document.getElementById('info-modal');
    const infoMsg = document.getElementById('info-message-text');
    const closeInfo = document.getElementById('close-info');
    const infoOk = document.getElementById('info-ok');
    function showInfo(msg) { infoMsg.innerText = msg; infoModal.classList.add('active'); }
    function closeInfoModal() { infoModal.classList.remove('active'); }
    if (closeInfo) closeInfo.onclick = closeInfoModal;
    if (infoOk) infoOk.onclick = closeInfoModal;
    window.onclick = (e) => { if (e.target === infoModal) closeInfoModal(); };

    // ------ Подтверждение отмены заказа ------
    let pendingCancelOrderId = null;
    const confirmCancelModal = document.getElementById('confirm-cancel-modal');
    const closeCancelConfirm = document.getElementById('close-cancel-confirm');
    const confirmCancelYes = document.getElementById('confirm-cancel-yes');
    const confirmCancelNo = document.getElementById('confirm-cancel-no');
    function closeCancelModal() { confirmCancelModal.classList.remove('active'); pendingCancelOrderId = null; }
    if (closeCancelConfirm) closeCancelConfirm.onclick = closeCancelModal;
    if (confirmCancelNo) confirmCancelNo.onclick = closeCancelModal;
    window.onclick = (e) => { if (e.target === confirmCancelModal) closeCancelModal(); };

    document.querySelectorAll('.cancel-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            pendingCancelOrderId = btn.dataset.id;
            confirmCancelModal.classList.add('active');
        });
    });
    if (confirmCancelYes) {
        confirmCancelYes.onclick = async () => {
            if (!pendingCancelOrderId) return;
            try {
                const res = await fetch(`index.php?route=cancel&id=${pendingCancelOrderId}`, { method: 'POST' });
                const data = await res.json();
                if (res.ok && data.status === 'cancelled') {
                    showInfo('Заказ отменён. Страница будет обновлена.');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showInfo(data.error || 'Не удалось отменить заказ');
                }
            } catch(err) { showInfo('Ошибка сети'); }
            closeCancelModal();
        };
    }

    // Редактирование: загрузка данных
    const editModal = document.getElementById('edit-modal');
    const closeEdit = document.getElementById('close-edit');
    if (closeEdit) closeEdit.onclick = () => editModal.classList.remove('active');
    window.onclick = (e) => { if (e.target === editModal) editModal.classList.remove('active'); };

    async function loadOrderForEdit(orderId) {
        try {
            const res = await fetch(`index.php?route=order&id=${orderId}`);
            const data = await res.json();
            if (data.id) {
                document.getElementById('edit-order-id').value = data.id;
                document.getElementById('edit-quantity').value = data.quantity;
                document.getElementById('edit-message').value = data.message || '';

                const productSelect = document.getElementById('edit-product');
                const products = [
                    {value:'vegetables', label:'Овощи (150 ₽/кг)'},
                    {value:'fruits', label:'Фрукты (300 ₽/кг)'},
                    {value:'milk', label:'Молочные продукты (200 ₽/л)'},
                    {value:'honey', label:'Мёд (400 ₽/бут)'},
                    {value:'cheese', label:'Сыр (500 ₽/кг)'}
                ];
                productSelect.innerHTML = '';
                products.forEach(p => {
                    const opt = document.createElement('option');
                    opt.value = p.value;
                    opt.textContent = p.label;
                    if (p.value === data.product_type) opt.selected = true;
                    productSelect.appendChild(opt);
                });

                const deliverySelect = document.getElementById('edit-delivery');
                deliverySelect.innerHTML = `
                    <option value="0">Самовывоз (бесплатно)</option>
                    <option value="300">По городу (300 ₽)</option>
                    <option value="500">За город (500 ₽)</option>
                `;
                deliverySelect.value = data.delivery_cost;

                const giftBtns = document.querySelectorAll('[data-opt="gift"]');
                const organicBtns = document.querySelectorAll('[data-opt="organic"]');
                const giftHidden = document.getElementById('edit-gift-hidden');
                const organicHidden = document.getElementById('edit-organic-hidden');

                function setActive(btns, val) {
                    btns.forEach(b => {
                        b.classList.remove('active');
                        if (parseInt(b.dataset.value) === val) b.classList.add('active');
                    });
                }
                setActive(giftBtns, data.gift_wrap);
                setActive(organicBtns, data.organic_cert);
                giftHidden.value = data.gift_wrap;
                organicHidden.value = data.organic_cert;

                giftBtns.forEach(btn => {
                    btn.onclick = () => {
                        giftBtns.forEach(b => b.classList.remove('active'));
                        btn.classList.add('active');
                        giftHidden.value = btn.dataset.value;
                    };
                });
                organicBtns.forEach(btn => {
                    btn.onclick = () => {
                        organicBtns.forEach(b => b.classList.remove('active'));
                        btn.classList.add('active');
                        organicHidden.value = btn.dataset.value;
                    };
                });

                editModal.classList.add('active');
            } else {
                showInfo('Не удалось загрузить заказ');
            }
        } catch(e) { showInfo('Ошибка загрузки заказа'); }
    }

    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', () => loadOrderForEdit(btn.dataset.id));
    });
});
</script>
</body>
</html>