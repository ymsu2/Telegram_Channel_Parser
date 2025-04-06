<?php
/*
Алгоритм работы парсера "Рейтинг Telegram-каналов (ТОП-100)"
Для обработки данных использовано 2 канала:
- рейтинг каналов по подписчикам https://tgstat.ru/ratings/channels?sort=members
- рейтинг каналов по цитируемости https://tgstat.ru/ratings/channels?sort=ci

С точки зрения рекламодателя 1-й канал важен для формирования целевой аудитории, а 2-й - для притока посетителей через поисковый трафик.
В алгоритме расчета индекса вовлеченности ER используются рейтинг канала (rating), число подписчиков (subscribers) и индекс цитирования (ci).
Рейтинг объединяется из двух источников: $channels_members (рейтинг по подписчикам) и $channels_ci (рейтинг по цитируемости). 
Объединенный рейтинг охватывает первые 100 каналов (при необходимости это количество можно расширить) с наиболее высокими показателем индекса вовлеченности ER.
Данные сохраняются в базе данных (sqlite3) и выводятся на экран.
Скрипт отправляет уведомление пользователю в Telegram, если канал в своей тематике в итоговом рейтинге превышает средний рейтинг.
Скрипт протестирован под php 7.4.33, php.ini настроен для работы с SSL (HTTPS), mbstring, sqlite3
Для парсинга использован php модуль cURL, класс DOMXPath и сопутствующие методы и свойства.

Примеры использования
http://localhost/tgstat_parser.php
http://parser/tgstat_parser.php
*/
set_time_limit(0);
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set("Europe/Moscow");

## для возможного использования в будущем
/*
$log_file = 'error.log';
function log_error($msg) {
    global $log_file;
    file_put_contents($log_file, "[" . date("Y-m-d H:i:s") . "] $msg\n", FILE_APPEND);
}
*/

// === конфигурация ===
define('DB_FILE', 'channels.db');
define('BOT_TOKEN', 'YOUR_BOT_TOKEN'); // ← вставьте ваш токен
define('CHAT_ID', 'YOUR_CHAT_ID');     // ← вставьте ваш Chat ID

function fetch_html($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    $html = curl_exec($ch);
    curl_close($ch);
    return $html;
}

## парсинг категорий
function parse_categories($html) {
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    // Получаем все ссылки в выпадающем меню
    $links = $xpath->query("//div[contains(@class, 'dropdown-menu')]//a[contains(@class, 'dropdown-item')]");
    $categories = [];
    $start_collecting = false;
    foreach ($links as $link) {
        $name = trim($link->nodeValue);
        $href = $link->getAttribute('href');

        // Ждём начала — после "Все категории"
        if ($name === 'Все категории') {
            $start_collecting = true;
            continue;
        }

        // Начали собирать категории
        if ($start_collecting) {
            $categories[] = [
                'name' => $name,
                'url' => 'https://tgstat.ru' . $href,
            ];

            // Останавливаемся на "Другое"
            if ($name === 'Другое') {
                break;
            }
        }
    }
    return $categories;
}

## парсинг каналов
function parse_channels($html) {
    $dom = new DOMDocument();
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));

    $xpath = new DOMXPath($dom);
    $cards = $xpath->query("//div[contains(@class, 'peer-item-row')]");

    $channels = [];

    foreach ($cards as $card) {

        // Аватар
        $url = $xpath->evaluate(".//a[contains(@href,'/channel/')]", $card)->item(0)->getAttribute("href");
        $image = $xpath->evaluate(".//img", $card)->item(0)->getAttribute("src");
        $image = strpos($image, 'http') === 0 ? $image : "https:" . $image;

        // Название
        $name = trim($xpath->evaluate(".//div[contains(@class,'font-16')]", $card)->item(0)->nodeValue);

        // Подписчики
        $subscribers_text = $xpath->evaluate(".//div[contains(text(),'подписчиков')]/preceding-sibling::h4", $card)->item(0)->nodeValue;
        $subscribers = (int)str_replace([' ', 'k'], ['', '000'], trim($subscribers_text));

        // Категория
        $categoryNode = $xpath->evaluate(".//div[contains(@class, 'text-truncate') and contains(@class,'font-12')]/span", $card);
        $category = $categoryNode->length > 0 ? trim($categoryNode->item(0)->nodeValue) : '';

        // Рейтинг (ранг)
        $rank_raw = $xpath->evaluate(".//div[contains(@class,'ribbon')]", $card);
        $rank = $rank_raw->length > 0 ? (int)str_replace('#', '', $rank_raw->item(0)->nodeValue) : 0;
        $rating = $rank; // Пока rating = rank, может быть другим параметром

        // Индекс цитирования
        $ci_node = $xpath->evaluate(".//div[contains(text(),'индекс цитирования')]/preceding-sibling::h4", $card);
        $ci = $ci_node->length > 0 ? (int)str_replace(' ', '', $ci_node->item(0)->nodeValue) : 0;

        $channels[] = [
            'rank' => $rank,
            'name' => $name,
            'subscribers' => $subscribers,
            'category' => $category,
            'url' => $url,
            'image' => $image,
            'rating' => $rating,
            'ci' => $ci
        ];
    }

    return $channels;
}


function send_to_telegram($message) {

    $bot_token = BOT_TOKEN;
    $chat_id = CHAT_ID;

    $url = "https://api.telegram.org/bot$bot_token/sendMessage";

    $params = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);

    $base = json_decode($result, true);
    // проверка на ошибки
    if (@$base["error_code"])
       return $base["error_code"]." : ".$base["description"];
    else
       return 'success';
}

function extract_channel_id($url) {
    if (preg_match('~/@([^/]+)~', $url, $matches)) {
        return '@' . $matches[1];
    }
    return null;
}

## Блок Сбора данных

// промежуточная точка тестирования
/*
использовать функцию parse_categories в данной версии нет необходимости, но можно использовать в случае необходимости дальнейших доработок
$html = file_get_contents('https://tgstat.ru/ratings/channels?sort=members');
$cat_map = parse_categories($html);
echo "cat_map<br><br>";
echo "<pre>";
print_r($cat_map);
echo "</pre>";
exit;
*/

// сбор данных из рейтинга каналов по подписчмкам
$html_members = fetch_html('https://tgstat.ru/ratings/channels?sort=members');
$channels_members = parse_channels($html_members);

// сбор данных из рейтинга каналов по цитируемости
$html_ci = fetch_html('https://tgstat.ru/ratings/channels?sort=ci');
$channels_ci = parse_channels($html_ci);


// промежуточная точка тестирования
/*
    echo "<pre>";
    print_r($channels_members);
    echo "</pre>";
    exit;

    echo "<pre>";
    print_r($channels_ci);
    echo "</pre>";
    exit;

*/

$combined_map = [];
foreach ($channels_members as $channel) {
    $combined_map[$channel['url']] = $channel;
}

// Объединение массивов с учётом всех параметров
foreach ($channels_ci as $channel) {
    $url = $channel['url'];
    if (isset($combined_map[$url])) {
        // Объединяем рейтинги как среднее арифметическое
        $combined_map[$url]['rating'] = round(($combined_map[$url]['rating'] + $channel['rating']) / 2, 1);
        $combined_map[$url]['ci'] = max($combined_map[$url]['ci'] ?? 0, $channel['ci']);
        $combined_map[$url]['subscribers'] = max($combined_map[$url]['subscribers'], $channel['subscribers']);
    } else {
        $combined_map[$url] = $channel;
    }
}

$all_channels = array_values($combined_map);

// Расчёт индекса вовлеченности ER
foreach ($all_channels as &$channel) {
    $base = max($channel['subscribers'], 1); // защита от деления на 0
    $er = (($channel['rating'] + $channel['ci']) / $base) * 100;
    $channel['er'] = round($er, 2);
}
unset($channel);

// Расчет среднего ER по категориям
$er_avg_by_category = [];
$er_count_by_category = [];

foreach ($all_channels as $channel) {
    $cat = $channel['category'];
    $er_avg_by_category[$cat] = ($er_avg_by_category[$cat] ?? 0) + $channel['er'];
    $er_count_by_category[$cat] = ($er_count_by_category[$cat] ?? 0) + 1;
}

foreach ($er_avg_by_category as $cat => $total_er) {
    $er_avg_by_category[$cat] = $total_er / $er_count_by_category[$cat];
}

// Подсчёт отклонения ER (%)
foreach ($all_channels as &$channel) {
    $cat = $channel['category'];
    $avg = $er_avg_by_category[$cat] ?? 0;
    $delta = $avg > 0 ? (($channel['er'] - $avg) / $avg) * 100 : 0;
    $channel['engagement_delta'] = ($delta > 0 ? '+' : '') . round($delta) . '%';
}
unset($channel);

// Сортировка по subscribers и ci
usort($all_channels, function ($a, $b) {
    if ($b['subscribers'] === $a['subscribers']) {
        return $b['ci'] <=> $a['ci'];
    }
    return $b['subscribers'] <=> $a['subscribers'];
});

// Усечение количества элементов в массиве до топ-100
$all_channels = array_slice($all_channels, 0, 100);

// промежуточная точка тестирования
/*
    echo "<pre>";
    print_r($all_channels);
    echo "</pre>";
    exit;
 */

// Подготовка: псевдонимы
foreach ($all_channels as &$channel) {
    $channel['avatar'] = $channel['image'];
    $channel['link'] = $channel['url'];
}
unset($channel);

// Расчёт среднего ER
$count_channels = count($all_channels);
$sum_er = array_sum(array_column($all_channels, 'er'));
$mean_er = $count_channels > 0 ? $sum_er / $count_channels : 1;

// Добавим er_percent и delta в каждый элемент
foreach ($all_channels as &$channel) {
    $channel['er_percent'] = round($channel['er'], 2);
    $channel['er_delta'] = $mean_er > 0 ? round((($channel['er'] - $mean_er) / $mean_er) * 100) : 0;
}
unset($channel);

// Сортировка по er_percent (по убыванию)
usort($all_channels, function($a, $b) {
    return $b['er_percent'] <=> $a['er_percent'];
});

/*
База данных (не обязательно SQLite3) может понадобится для будущих доработок
*/

// Подключение к SQLite
$db = new SQLite3(DB_FILE);

// Создание таблицы, если не существует
$db->exec("
    CREATE TABLE IF NOT EXISTS channels (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT,
        url TEXT,
        image TEXT,
        category TEXT,
        subscribers INTEGER,
        rating REAL,
        er REAL,
        ci INTEGER
    )
");

// Очистить таблицу перед вставкой (если нужно)
$db->exec("DELETE FROM channels");

// Вставка данных
$stmt = $db->prepare("
    INSERT INTO channels (name, url, image, category, subscribers, rating, er, ci)
    VALUES (:name, :url, :image, :category, :subscribers, :rating, :er, :ci)
");

foreach ($all_channels as $channel) {
    $stmt->bindValue(':name', $channel['name'], SQLITE3_TEXT);
    $stmt->bindValue(':url', $channel['link'], SQLITE3_TEXT);
    $stmt->bindValue(':image', $channel['avatar'], SQLITE3_TEXT);
    $stmt->bindValue(':category', $channel['category'], SQLITE3_TEXT);
    $stmt->bindValue(':subscribers', (int)$channel['subscribers'], SQLITE3_INTEGER);
    $stmt->bindValue(':rating', (float)$channel['rating'], SQLITE3_FLOAT);
    $stmt->bindValue(':er', (float)$channel['er_percent'], SQLITE3_FLOAT);
    $stmt->bindValue(':ci', isset($channel['ci']) ? (int)$channel['ci'] : 0, SQLITE3_INTEGER);
    $stmt->execute();
}

// Вывод на экран содержимого массива $all_channels в виде таблицы
echo "<table border='1' cellpadding='5' cellspacing='0'>";
echo "<caption>Рейтинг Telegram-каналов (ТОП-100)</caption>";

echo "<tr>
        <th>№ п/п</th>
        <th>Аватар</th>
        <th>Название</th>
        <th>Категория</th>
        <th>Индекс вовлеченности ER (%)</th>
        <th>Подписчики</th>
        <th>Индекс цитирования</th>
      </tr>";

$n = 0;
foreach ($all_channels as $channel) {
    $n++;
    /*
    # использовать когда необходимо отпраить уведомление если рейтинг выше среднего
    $delta = $channel['er_delta'];
    $delta_str = ($delta > 0 ? '+' : '') . $delta . '%';
    */

    # использовать когда необходимо отпраить уведомление если рейтинг выше среднего по категории
    $delta_str = $channel['engagement_delta']; // Используем отклонение по категории
    /*
    # использовать когда необходимо отпраить уведомление если рейтинг выше среднего
    if ($delta > 0) { // если рейтинг превышает общий средний рейтинг
    */
    
    if (strpos($delta_str, '+') === 0) { // Если отклонение положительное, использовать когда необходимо отпраить уведомление если рейтинг выше среднего по категории
        /*
        # использовать когда необходимо отпраить уведомление если рейтинг выше среднего
        $msg = "<b>{$channel['name']}</b> имеет вовлеченность выше среднего на <b>{$delta_str}</b>\n"."Категория: <b>{$channel['category']}</b>\nСсылка: {$channel['link']}";
        */
        $msg = "<b>{$channel['name']}</b> имеет вовлеченность выше среднего в категории <b>{$channel['category']}</b> на <b>{$delta_str}</b>\nСсылка: {$channel['link']}";
        $result = send_to_telegram($msg);


        // если произошла ошибка при доставке сообщений в телеграм - дублируем вывод уведомлений в лог
        if ( $result <> 'success')
            file_put_contents("log_to_telegram.log", "\r\nСообщения не доставлены в чат. Причина: $result\r\nПроверьте настройки конфигурации.\r\n ".$msg, FILE_APPEND); 
    }

    echo "<tr>";
    echo "<td>$n</td>";
    echo "<td><img src='{$channel['avatar']}' width='50'></td>";
    echo "<td><a href='{$channel['link']}' target='_blank'>{$channel['name']}</a></td>";
    echo "<td>{$channel['category']}</td>";
    echo "<td>{$channel['er_percent']}%</td>";
    echo "<td>{$channel['subscribers']}</td>";
    echo "<td>{$channel['ci']}</td>";
    echo "</tr>";
}
echo "</table>";
?>