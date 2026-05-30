// ========== ГЛОБАЛЬНЫЕ ПЕРЕМЕННЫЕ И СОСТОЯНИЕ ==========
document.addEventListener('DOMContentLoaded', function() {
    console.log('Сайт "Клеш Рояль" загружен!');
    
    // Состояние слайдера галереи
    let currentSlide = 0;
    const totalSlides = document.querySelectorAll('.gallery-slide').length;
    
    // ========== ИНИЦИАЛИЗАЦИЯ МОБИЛЬНОГО МЕНЮ ==========
    const burgerBtn = document.getElementById('burgerBtn');
    const mobileMenu = document.createElement('div');
    const mobileOverlay = document.createElement('div');
    
    // Создаём мобильное меню и добавляем его в тело документа
    function initMobileMenu() {
        if (!burgerBtn) return;
        
        // Создаём структуру мобильного меню
        mobileMenu.className = 'mobile-menu';
        mobileMenu.innerHTML = `
            <button class="menu-close" id="menuClose"><i class="fas fa-times"></i></button>
            <ul>
                <li><a href="#"><i class="fas fa-home"></i> Главная</a></li>
                <li><a href="#products"><i class="fas fa-carrot"></i> Урожай</a></li>
                <li><a href="#calculator"><i class="fas fa-calculator"></i> Калькулятор</a></li>
                <li><a href="#gallery"><i class="fas fa-images"></i> Галерея</a></li>
                <li><a href="#contact"><i class="fas fa-address-book"></i> Контакты</a></li>
                <li><a href="#" id="mobile-contact-btn" class="btn"><i class="fas fa-comment-dots"></i> Связь с нами</a></li>
            </ul>
        `;
        
        // Создаём оверлей (затемнение фона)
        mobileOverlay.className = 'mobile-overlay';
        
        document.body.appendChild(mobileMenu);
        document.body.appendChild(mobileOverlay);
        
        const menuCloseBtn = document.getElementById('menuClose');
        const mobileContactBtn = document.getElementById('mobile-contact-btn');
        
        // Открытие мобильного меню
        burgerBtn.addEventListener('click', function() {
            mobileMenu.classList.add('active');
            mobileOverlay.classList.add('active');
            burgerBtn.classList.add('active');
            document.body.style.overflow = 'hidden'; // Блокируем прокрутку страницы
        });
        
        // Закрытие мобильного меню
        function closeMobileMenu() {
            mobileMenu.classList.remove('active');
            mobileOverlay.classList.remove('active');
            burgerBtn.classList.remove('active');
            document.body.style.overflow = '';
        }
        
        menuCloseBtn.addEventListener('click', closeMobileMenu);
        mobileOverlay.addEventListener('click', closeMobileMenu);
        
        // Закрытие меню при клике на ссылку внутри него
        mobileMenu.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', closeMobileMenu);
        });
        
        // Кнопка "Связь с нами" в мобильном меню открывает модальное окно
        if (mobileContactBtn) {
            mobileContactBtn.addEventListener('click', function(e) {
                e.preventDefault();
                closeMobileMenu();
                openModal();
            });
        }
    }
    
    // ========== ВЫБОР ЦВЕТА ПРОДУКТА (СМЕНА ИЗОБРАЖЕНИЯ) ==========
    function initColorPickers() {
        const colorOptions = document.querySelectorAll('.color-option');
        
        console.log('Найдено элементов для выбора цвета:', colorOptions.length);
        
        colorOptions.forEach(option => {
            option.addEventListener('click', function() {
                const productId = this.getAttribute('data-product');
                const imgUrl = this.getAttribute('data-img');
                
                console.log('Клик по цвету:', productId, imgUrl);
                
                // Убираем активный класс у всех кнопок в этой группе
                const parent = this.closest('.color-options');
                parent.querySelectorAll('.color-option').forEach(opt => {
                    opt.classList.remove('active');
                });
                
                // Добавляем активный класс нажатой кнопке
                this.classList.add('active');
                
                // Меняем основное изображение продукта
                const productImg = document.getElementById(`product-${productId}-img`);
                if (productImg && imgUrl) {
                    productImg.src = imgUrl;
                    productImg.alt = this.getAttribute('title') || 'Продукт';
                    console.log('Изображение изменено на:', imgUrl);
                } else {
                    console.error('Не найден элемент productImg или URL');
                }
            });
        });
    }
    
    // ========== СЛАЙДЕР ГАЛЕРЕИ ==========
    function initGallerySlider() {
        const slider = document.querySelector('.gallery-slider');
        const prevBtn = document.getElementById('prevBtn');
        const nextBtn = document.getElementById('nextBtn');
        const dots = document.querySelectorAll('.gallery-dot');
        
        if (!slider || !prevBtn || !nextBtn) return;
        
        // Функция обновления положения слайдера и активной точки
        function updateSlider() {
            // Сдвигаем дорожку слайдера на ширину текущего слайда
            slider.style.transform = `translateX(-${currentSlide * 100}%)`;
            
            // Обновляем активную точку
            dots.forEach((dot, index) => {
                dot.classList.toggle('active', index === currentSlide);
            });
        }
        
        // Переход к следующему слайду
        function nextSlide() {
            currentSlide = (currentSlide + 1) % totalSlides;
            updateSlider();
        }
        
        // Переход к предыдущему слайду
        function prevSlide() {
            currentSlide = (currentSlide - 1 + totalSlides) % totalSlides;
            updateSlider();
        }
        
        // Переход к конкретному слайду по клику на точку
        function goToSlide(slideIndex) {
            currentSlide = slideIndex;
            updateSlider();
        }
        
        // Назначаем обработчики событий
        nextBtn.addEventListener('click', nextSlide);
        prevBtn.addEventListener('click', prevSlide);
        
        dots.forEach(dot => {
            dot.addEventListener('click', function() {
                const slideIndex = parseInt(this.getAttribute('data-slide'));
                goToSlide(slideIndex);
            });
        });
        
        // Автопрокрутка слайдера каждые 5 секунд
        let autoSlideInterval = setInterval(nextSlide, 5000);
        
        // Останавливаем автопрокрутку при наведении мыши на слайдер
        slider.addEventListener('mouseenter', () => {
            clearInterval(autoSlideInterval);
        });
        
        // Возобновляем автопрокрутку, когда мышь убрали
        slider.addEventListener('mouseleave', () => {
            autoSlideInterval = setInterval(nextSlide, 5000);
        });
        
        // Инициализация начального состояния
        updateSlider();
    }
    
    // ========== КАЛЬКУЛЯТОР СТОИМОСТИ ==========
    function initCalculator() {
        const productSelect = document.getElementById('product');
        const quantitySlider = document.getElementById('quantity');
        const quantityValue = document.getElementById('quantityValue');
        const deliverySelect = document.getElementById('delivery');
        const totalPriceElement = document.getElementById('total-price');
        const checkboxes = document.querySelectorAll('input[type="checkbox"]');
        
        if (!productSelect || !quantitySlider || !deliverySelect || !totalPriceElement) return;
        
        // Функция форматирования числа (добавляет пробелы между разрядами)
        function formatNumber(num) {
            return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
        }
        
        // Основная функция пересчёта стоимости
        function calculateTotal() {
            // Получаем значения из формы
            const productPrice = parseInt(productSelect.value);
            const quantity = parseInt(quantitySlider.value);
            const deliveryCost = parseInt(deliverySelect.value);
            
            // Считаем стоимость дополнительных опций
            let extraCost = 0;
            checkboxes.forEach(checkbox => {
                if (checkbox.checked) {
                    extraCost += parseInt(checkbox.value);
                }
            });
            
            // Вычисляем итоговую стоимость
            const totalPrice = (productPrice * quantity) + deliveryCost + extraCost;
            
            // Обновляем отображаемые значения
            quantityValue.textContent = quantity;
            totalPriceElement.textContent = formatNumber(totalPrice) + ' ₽';
            
            // Получаем текстовые названия выбранных опций для подсказки
            const productText = productSelect.options[productSelect.selectedIndex].text.split(' (')[0];
            const deliveryText = deliverySelect.options[deliverySelect.selectedIndex].text.split(' (')[0];
            
            // Формируем подробную подсказку
            let hintText = `(${quantity} ${productText.toLowerCase()} × ${formatNumber(productPrice)} ₽`;
            if (deliveryCost > 0) hintText += ` + доставка ${formatNumber(deliveryCost)} ₽`;
            if (extraCost > 0) hintText += ` + опции ${formatNumber(extraCost)} ₽`;
            hintText += `)`;
            
            // Обновляем текст подсказки под итоговой ценой
            const hintElement = document.querySelector('.hint');
            if (hintElement) {
                hintElement.textContent = hintText;
            }
        }
        
        // Назначаем обработчики событий на все элементы калькулятора
        productSelect.addEventListener('change', calculateTotal);
        quantitySlider.addEventListener('input', calculateTotal);
        deliverySelect.addEventListener('change', calculateTotal);
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', calculateTotal);
        });
        
        // Инициализация при загрузке страницы
        calculateTotal();
    }
    
    // ========== ДИНАМИЧЕСКИЙ БЛОГ ==========
    function initBlog() {
        const blogGrid = document.getElementById('blog-grid');
        const categoryBtns = document.querySelectorAll('.category-btn');
        const searchInput = document.getElementById('blog-search');
        const searchBtn = document.getElementById('searchBtn');
        const loadMoreBtn = document.getElementById('load-more-btn');
        
        if (!blogGrid) {
            console.error('Не найден элемент blog-grid');
            return;
        }
        
        console.log('Инициализация блога...');
        
        // Массив с данными статей блога
        const blogPosts = [
            { id: 1, category: 'tips', date: '20 марта 2024 г.', title: 'Как выбрать самые свежие овощи?', content: 'Несколько простых советов, которые помогут вам выбрать самые качественные и спелые овощи на рынке.' },
            { id: 2, category: 'news', date: '15 марта 2024 г.', title: 'Открытие нового тепличного комплекса', content: 'Мы рады сообщить о запуске современной теплицы, которая позволит нам выращивать овощи круглый год.' },
            { id: 3, category: 'events', date: '10 марта 2024 г.', title: 'Экскурсия на ферму для детей', content: 'В минувшие выходные мы провели увлекательную экскурсию для школьников, чтобы показать, как работает ферма.' },
            { id: 4, category: 'tips', date: '5 марта 2024 г.', title: 'Домашний пирог с фермерскими ягодами', content: 'Поделимся с вами простым и вкусным рецептом пирога, который можно приготовить из нашей клубники.' },
            { id: 5, category: 'news', date: '28 февраля 2024 г.', title: 'Новая порода кур-несушек', content: 'На ферму прибыли куры редкой породы, которые несут разноцветные яйца!' },
            { id: 6, category: 'events', date: '20 февраля 2024 г.', title: 'Фестиваль урожая "Клеш Рояль"', content: 'Приглашаем всех на наш ежегодный фестиваль урожая с конкурсами, угощениями и экскурсиями.' }
        ];
        
        let currentCategory = 'all';
        let currentSearch = '';
        let displayedPosts = 3; // Сколько статей показывать изначально
        
        // Функция отображения статей с учётом фильтров
        function displayPosts() {
            blogGrid.innerHTML = '';
            
            // Фильтрация статей по категории и поисковому запросу
            const filteredPosts = blogPosts.filter(post => {
                const matchesCategory = currentCategory === 'all' || post.category === currentCategory;
                const matchesSearch = currentSearch === '' || 
                    post.title.toLowerCase().includes(currentSearch.toLowerCase()) ||
                    post.content.toLowerCase().includes(currentSearch.toLowerCase());
                return matchesCategory && matchesSearch;
            });
            
            console.log('Отфильтровано статей:', filteredPosts.length);
            
            // Отображение только части статей (для кнопки "Загрузить больше")
            const postsToShow = filteredPosts.slice(0, displayedPosts);
            
            // Если статей нет, показываем сообщение
            if (postsToShow.length === 0) {
                blogGrid.innerHTML = '<div class="no-posts"><p>Статьи не найдены. Попробуйте изменить критерии поиска.</p></div>';
                if (loadMoreBtn) loadMoreBtn.style.display = 'none';
                return;
            }
            
            // Создание HTML-карточек для каждой статьи
            postsToShow.forEach(post => {
                const postElement = document.createElement('article');
                postElement.className = 'blog-card';
                postElement.innerHTML = `
                    <div class="blog-category">${getCategoryName(post.category)}</div>
                    <div class="blog-date">${post.date}</div>
                    <h3>${post.title}</h3>
                    <p>${post.content}</p>
                `;
                blogGrid.appendChild(postElement);
            });
            
            console.log('Отображено статей:', postsToShow.length);
            
            // Показываем/скрываем кнопку "Загрузить больше"
            if (loadMoreBtn) {
                if (displayedPosts >= filteredPosts.length) {
                    loadMoreBtn.style.display = 'none';
                } else {
                    loadMoreBtn.style.display = 'block';
                }
            }
        }
        
        // Преобразуем код категории в читаемое название
        function getCategoryName(categoryCode) {
            const names = {
                'news': 'Новости',
                'tips': 'Советы',
                'events': 'События'
            };
            return names[categoryCode] || 'Блог';
        }
        
        // Фильтрация по категориям
        categoryBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                // Убираем активный класс у всех кнопок
                categoryBtns.forEach(b => b.classList.remove('active'));
                // Добавляем активный класс нажатой кнопке
                this.classList.add('active');
                // Устанавливаем текущую категорию
                currentCategory = this.getAttribute('data-category');
                displayedPosts = 3; // Сбрасываем счётчик показанных статей
                displayPosts();
            });
        });
        
        // Поиск по статьям
        if (searchInput && searchBtn) {
            searchBtn.addEventListener('click', function() {
                currentSearch = searchInput.value;
                displayedPosts = 3;
                displayPosts();
            });
            
            searchInput.addEventListener('keyup', function(e) {
                if (e.key === 'Enter') {
                    currentSearch = searchInput.value;
                    displayedPosts = 3;
                    displayPosts();
                }
            });
        }
        
        // Кнопка "Загрузить больше"
        if (loadMoreBtn) {
            loadMoreBtn.addEventListener('click', function() {
                displayedPosts += 3;
                displayPosts();
            });
        }
        
        // Инициализация блога при загрузке страницы
        displayPosts();
    }
     initMobileMenu();
    initColorPickers();
    initGallerySlider();
    //initCalculator();
    initBlog();
    // ========== ДОПОЛНИТЕЛЬНЫЕ ФУНКЦИИ ==========
    
    // Плавная прокрутка к якорным ссылкам
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            const href = this.getAttribute('href');
            
            // Пропускаем ссылки на другие страницы и ссылки "#"
            if (href === '#' || href.includes('javascript')) return;
            
            const targetElement = document.querySelector(href);
            if (targetElement) {
                e.preventDefault();
                
                // Плавная прокрутка к элементу
                window.scrollTo({
                    top: targetElement.offsetTop - 80,
                    behavior: 'smooth'
                });
            }
        });
    });
    
    // Обновление года в футере
    const yearElements = document.querySelectorAll('.copyright');
    yearElements.forEach(el => {
        if (el.textContent.includes('2023')) {
            el.textContent = el.textContent.replace('2023', new Date().getFullYear());
        }
    });
    
    console.log('Все модули сайта успешно инициализированы!');
});
