<?php
// === オーナーパスコード設定 ===
// ここでオーナー用のパスコード（半角英数字）を設定してください。
// 設置者は「your-password」を任意のパスワードに書き換えてください。
define('OWNER_PASSCODE', 'your-password');
// ============================
// 排便記録アプリ
// セッション管理を削除（localStorageで管理するため）

// CSVファイルのパス
$csv_file = 'defecation_log.csv';

// CSVファイルが存在しない場合は作成
if (!file_exists($csv_file)) {
    file_put_contents($csv_file, '');
}

// CSVデータを読み込む関数（最新順）
function readCsvData() {
    global $csv_file;
    $data = [];
    
    if (file_exists($csv_file) && filesize($csv_file) > 0) {
        $lines = file($csv_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $parts = explode(',', $line);
            if (count($parts) >= 2) {
                $date = trim($parts[0]);
                $count = (int)trim($parts[1]);
                $data[$date] = $count; // 連想配列で重複を防ぐ
            }
        }
    }
    
    return $data;
}

// CSVに書き込む関数
function writeCsvData($data, $preserve_mtime = false) {
    global $csv_file;
    
    // 日付順にソート
    ksort($data);
    
    $csv_content = '';
    foreach ($data as $date => $count) {
        $csv_content .= $date . ',' . $count . "\n";
    }

    // 書き込み前のタイムスタンプを取得
    $mtime = file_exists($csv_file) ? filemtime($csv_file) : false;
    file_put_contents($csv_file, $csv_content);
    // 書き込み後、フラグがtrueならタイムスタンプを元に戻す
    if ($preserve_mtime && $mtime !== false) {
        touch($csv_file, $mtime);
    }
}

// 今日の回数を更新する関数
function updateTodayCount($change) {
    $today = date('Ymd');
    $data = readCsvData();

    // 直前の記録日を取得（今日より前で最大の日付）
    $prev_dates = array_filter(array_keys($data), function($d) use ($today) {
        return $d < $today;
    });
    $last_date = $prev_dates ? max($prev_dates) : null;

    // 直前の記録日から今日までの間に抜けている日があれば0回で埋める
    if ($last_date !== null) {
        $dt = DateTime::createFromFormat('Ymd', $last_date);
        $dt->modify('+1 day');
        while ($dt->format('Ymd') < $today) {
            $d = $dt->format('Ymd');
            if (!isset($data[$d])) {
                $data[$d] = 0;
            }
            $dt->modify('+1 day');
        }
    }

    // 今日のデータを更新
    if (isset($data[$today])) {
        $data[$today] = max(0, $data[$today] + $change);
    } else {
        $data[$today] = max(0, $change);
    }

    // デクリメント時のみタイムスタンプ維持
    $preserve = ($change < 0);
    writeCsvData($data, $preserve);
}

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'increment':
                // 認証チェックはJavaScript側で行う
                updateTodayCount(1);
                break;
            case 'decrement':
                // 認証チェックはJavaScript側で行う
                updateTodayCount(-1);
                break;
            case 'save_csv':
                // 認証チェックはJavaScript側で行う
                if (isset($_POST['csv_content'])) {
                    // 編集内容をパース
                    $lines = explode("\n", trim($_POST['csv_content']));
                    $data = [];
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if ($line) {
                            $parts = explode(',', $line);
                            if (count($parts) >= 2) {
                                $date = trim($parts[0]);
                                $count = (int)trim($parts[1]);
                                $data[$date] = $count;
                            }
                        }
                    }
                    writeCsvData($data, true); // 編集タブ保存時はタイムスタンプ維持
                }
                break;
        }
    }
    
    // POSTリダイレクト
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// データを取得
$data = readCsvData();

// 今日の回数を取得
$today = date('Ymd');
$today_count = isset($data[$today]) ? $data[$today] : 0;

// 過去1週間の合計を計算（昨日から7日前まで）
$week_total = 0;
for ($i = 1; $i <= 7; $i++) {
    $date = date('Ymd', strtotime("-{$i} days"));
    if (isset($data[$date])) {
        $week_total += $data[$date];
    }
}

// 最終更新時間を取得
$last_modified = '';
if (file_exists($csv_file) && filesize($csv_file) > 0) {
    $mtime = filemtime($csv_file);
    $mod_date = new DateTime();
    $mod_date->setTimestamp($mtime);
    $now = new DateTime();
    
    if ($mod_date->format('Ymd') === $now->format('Ymd')) {
        $last_modified = $mod_date->format('H:i');
    } else {
        $last_modified = $now->format('Ymd') - $mod_date->format('Ymd') . '日前';
    }
}

// 履歴データ（最新30日分）
$history = [];
$dates = array_keys($data);
rsort($dates); // 最新順
$history_dates = array_slice($dates, 0, 30);
foreach ($history_dates as $date) {
    $history[] = [$date, $data[$date]];
}

// CSVの内容を取得（編集タブ用・最新順）
$csv_content = '';
if (!empty($data)) {
    $dates = array_keys($data);
    rsort($dates);
    $csv_lines = [];
    foreach ($dates as $date) {
        $csv_lines[] = $date . ',' . $data[$date];
    }
    $csv_content = implode("\n", $csv_lines);
}

// 曜日を取得する関数
function getWeekday($date_str) {
    $date = DateTime::createFromFormat('Ymd', $date_str);
    $weekdays = ['日', '月', '火', '水', '木', '金', '土'];
    return $weekdays[$date->format('w')];
}

// 日付をフォーマットする関数
function formatDate($date_str) {
    $date = DateTime::createFromFormat('Ymd', $date_str);
    return $date->format('Y/m/d');
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>排便回数ログ</title>
    <link rel="icon" type="image/svg+xml" href='data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><text y=".9em" font-size="90">💩</text></svg>'>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            /*background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);*/
            /*background: linear-gradient(135deg, #667eea 0%, #a68ba2 100%);*/
            /*background: linear-gradient(135deg, #6790e1 0%, #5d87b4 100%);*/
            background: linear-gradient(135deg, #5681d8 0%, #5d87b4 100%);
            min-height: 100vh;
            padding: 10px;
        }
        
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            overflow: hidden;
            backdrop-filter: blur(10px);
        }
        
        .header {
            background: linear-gradient(135deg, #4facfe 0%, #1f00e8 100%);
            padding: 20px;
            text-align: center;
            color: white;
        }
        
        .header h1 {
            font-size: 1.5rem;
            font-weight: 300;
        }
        
        .tabs {
            display: flex;
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
        }
        
        .tab {
            flex: 1;
            padding: 12px;
            text-align: center;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            color: #6c757d;
            transition: all 0.3s ease;
        }
        
        .tab.active {
            background: white;
            color: #4facfe;
            border-bottom: 3px solid #4facfe;
        }
        
        .tab-content {
            padding: 20px;
        }
        
        .today-section {
            background: linear-gradient(135deg, #5681d8 0%, #5d87b4 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background 0.3s ease;
        }

        .today-section.updated {
            background: linear-gradient(135deg, #e34e51 0%, #c82333 100%);
        }

        .today-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .today-count {
            font-size: 2rem;
            font-weight: 300;
        }
        
        .counter-buttons {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            background: rgba(255, 255, 255, 0.2);
            border: 2px solid rgba(255, 255, 255, 0.3);
            color: white;
            padding: 10px;
            border-radius: 25px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            transition: all 0.2s ease;
            backdrop-filter: blur(10px);
        }
        
        .btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-1px);
        }
        
        .btn.updated {
            transform: scale(1.2);
            background: rgba(255, 255, 255, 0.4);
        }
        
        .btn.disabled {
            opacity: 0.5;
            pointer-events: none;
        }
        
        .week-summary {
            background: #f8f9fa;
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .week-summary h3 {
            color: #495057;
            font-size: 1rem;
        }
        
        .week-total {
            font-size: 1.5rem;
            font-weight: bold;
            color: #4facfe;
        }
        
        .history-section h3 {
            color: #495057;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1rem;
        }
        
        .last-updated {
            font-size: 0.85rem;
            color: #6c757d;
            margin-left: auto;
        }
        
        .history-list {
            background: #f8f9fa;
            border-radius: 12px;
            overflow: hidden;
        }
        
        .history-item {
            padding: 8px 15px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            -webkit-justify-content: flex-start;
            justify-content: flex-start;
            gap: 10px;
        }

        .history-item.recent-week {
            background-color: #FFBBAF28;
        }
        
        .history-item:last-child {
            border-bottom: none;
        }

        .history-item div:last-of-type {
          margin-left: auto;
        }

        .history-date {
            font-weight: 500;
            color: #495057;
            width: 80px;
            text-align:center;
        }


        .history-weekday {
            font-weight: 500;
            color: #6c757d;
            font-size: 0.8rem;
            width: 30px;
            text-align:center;
        }
        
        .history-weekday.Sun {
            color: #d03445;
        }
        .history-weekday.Sat {
            color: #3456ed;
        }

        .history-count {
            background: #4facfe;
            color: white;
            padding: 4px 10px;
            border-radius: 15px;
            font-weight: bold;
            min-width: 35px;
            text-align: center;
            font-size: 0.9rem;
        }

        .history-count.zero {
            background: #6c757d8a;
        }
        
        .edit-section {
            text-align: center;
        }
        
        .edit-section textarea {
            width: 100%;
            height: 300px;
            padding: 15px;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            font-family: 'Courier New', monospace;
            font-size: 13pt;
            resize: vertical;
            background: #f8f9fa;
        }
        
        .save-btn {
            background: linear-gradient(135deg, #4facfe 0%, #1f00e8 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 25px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            margin-top: 15px;
            transition: all 0.2s ease;
        }
        
        .save-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 5px 15px rgba(79, 172, 254, 0.3);
        }
        
        .save-btn.disabled {
            opacity: 0.5;
            pointer-events: none;
        }
        
        .auth-section {
            text-align: center;
            padding: 20px;
        }
        
        .auth-section input[type="password"] {
            padding: 10px;
            margin: 10px;
            width: 200px;
            border-radius: 5px;
            border: 1px solid #ccc;
        }
        
        .hidden {
            display: none;
        }
        
        .auth-message {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 15px 25px;
            border-radius: 8px;
            z-index: 1000;
            font-size: 14px;
            width: 300px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-chart-line"></i> 排便記録</h1>
        </div>
        
        <div class="tabs">
            <button class="tab active" onclick="showTab('list')"><i class="fas fa-list"></i> 一覧</button>
            <button class="tab" onclick="showTab('edit')"><i class="fas fa-edit"></i> 編集</button>
            <button class="tab" onclick="showTab('auth')"><i class="fas fa-user-lock"></i> 開錠</button>
        </div>
        
        <div id="list-tab" class="tab-content">
            <div class="today-section">
                <div class="today-info">
                    <span>今日</span>
                    <span class="today-count"><?= $today_count ?> 回</span>
                </div>
                <div class="counter-buttons">
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="action" value="increment">
                        <button type="submit" class="btn" id="incrementBtn"><i class="fas fa-plus"></i></button>
                    </form>
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="action" value="decrement">
                        <button type="submit" class="btn" id="decrementBtn"><i class="fas fa-minus"></i></button>
                    </form>
                </div>
            </div>
            
            <div class="week-summary">
                <h3>過去1週間の合計</h3>
                <div class="week-total"><?= $week_total ?> 回</div>
            </div>
            
            <div class="history-section">
                <h3>
                    <i class="fas fa-history"></i>
                    履歴（直近30日）
                    <?php if ($last_modified): ?>
                        <span class="last-updated">最終更新 <?= $last_modified ?></span>
                    <?php endif; ?>
                </h3>
                <div class="history-list">
                    <?php 
                    $today = new DateTime();
                    $yesterday = clone $today;
                    $yesterday->modify('-1 day');
                    $week_ago = clone $yesterday;
                    $week_ago->modify('-7 days');
                    
                    foreach ($history as $row): 
                        $date = DateTime::createFromFormat('Ymd', $row[0]);
                        $is_recent_week = ($date >= $week_ago && $date <= $yesterday);
                    ?>
                        <div class="history-item <?= $is_recent_week ? 'recent-week' : '' ?>">
                            <div class="history-date"><?= formatDate($row[0]) ?></div>
                            <div class="history-weekday <?= (['日' => 'Sun', '土' => 'Sat'][getWeekday($row[0])] ?? '') ?>"><?= getWeekday($row[0]) ?></div>
                            <div class="history-count <?= $row[1] == 0 ? 'zero' : '' ?>"><?= $row[1] ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <div id="edit-tab" class="tab-content hidden">
            <div class="edit-section">
                <form method="post">
                    <textarea name="csv_content" placeholder="CSVデータを編集してください..."><?= htmlspecialchars($csv_content) ?></textarea>
                    <input type="hidden" name="action" value="save_csv">
                    <button type="submit" class="save-btn" id="saveBtn">
                        <i class="fas fa-save"></i> 上書き保存
                    </button>
                </form>
            </div>
        </div>

        <div id="auth-tab" class="tab-content hidden">
            <div class="auth-section">
                <div id="authForm">
                    <div id="authFormContent">
                        <p>オーナー認証を行い、書き込みロックを解除します。</p>
                        <form id="authenticateForm" style="margin-bottom: 20px;">
                            <input type="password" name="passcode" placeholder="パスコードを入力" style="padding: 10px; margin: 10px; width: 200px; border-radius: 5px; border: 1px solid #ccc;">
                            <button type="submit" class="save-btn">
                                <i class="fas fa-user-lock"></i> オーナー認証
                            </button>
                        </form>
                    </div>
                    <div id="deauthenticateForm" style="display: none;">
                        <p>オーナー認証を解除し、本ブラウザでの書き込みをロックします。</p>
                        <button type="button" class="save-btn" id="deauthenticateBtn" style="background: #E34E51;">
                            <i class="fas fa-user-slash"></i> オーナー認証解除
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        let currentTab = 'list';
        const tabs = ['list', 'edit', 'auth'];
        const STORAGE_KEY = 'defecation_logger_isOwner';

        // localStorageから認証状態を取得
        function getOwnerStatus() {
            return localStorage.getItem(STORAGE_KEY) === 'true';
        }

        // localStorageに認証状態を保存
        function setOwnerStatus(isOwner) {
            localStorage.setItem(STORAGE_KEY, isOwner.toString());
        }

        // 認証状態に応じてUIを更新
        function updateUIByAuthStatus() {
            const isOwner = getOwnerStatus();
            const incrementBtn = document.getElementById('incrementBtn');
            const decrementBtn = document.getElementById('decrementBtn');
            const saveBtn = document.getElementById('saveBtn');
            const authFormContent = document.getElementById('authFormContent');
            const deauthenticateForm = document.getElementById('deauthenticateForm');
            
            // ボタンの有効/無効を切り替え
            if (incrementBtn) incrementBtn.classList.toggle('disabled', !isOwner);
            if (decrementBtn) decrementBtn.classList.toggle('disabled', !isOwner);
            if (saveBtn) saveBtn.classList.toggle('disabled', !isOwner);
            
            // 認証フォームの表示を切り替え
            if (authFormContent) authFormContent.style.display = isOwner ? 'none' : 'block';
            if (deauthenticateForm) deauthenticateForm.style.display = isOwner ? 'block' : 'none';
        }

        // 認証メッセージを表示
        function showAuthMessage(message) {
            const msgDiv = document.createElement('div');
            msgDiv.className = 'auth-message';
            msgDiv.textContent = message;
            document.body.appendChild(msgDiv);
            
            setTimeout(() => {
                msgDiv.remove();
            }, 2000);
        }

        function showTab(tabName) {
            currentTab = tabName;
            // タブボタンの状態を更新
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // タブコンテンツの表示を切り替え
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.add('hidden');
            });
            document.getElementById(tabName + '-tab').classList.remove('hidden');
        }

        // キーボードイベントの処理
        document.addEventListener('keydown', function(e) {
            if (e.key === 'PageUp' || e.key === 'PageDown') {
                e.preventDefault();
                const currentIndex = tabs.indexOf(currentTab);
                let newIndex;
                
                if (e.key === 'PageUp') {
                    newIndex = (currentIndex - 1 + tabs.length) % tabs.length;
                } else {
                    newIndex = (currentIndex + 1) % tabs.length;
                }
                
                const newTab = tabs[newIndex];
                document.querySelector(`.tab[onclick="showTab('${newTab}')"]`).click();
            }
        });

        // 認証フォームの処理
        document.getElementById('authenticateForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const passcode = this.querySelector('input[name="passcode"]').value;
            
            if (passcode === '<?php echo addslashes(OWNER_PASSCODE); ?>') {
                setOwnerStatus(true);
                updateUIByAuthStatus();
                showAuthMessage('認証成功！オーナー権限が有効になりました。');
                showTab('auth');
            } else {
                showAuthMessage('パスコードが正しくありません。');
            }
        });

        // 認証解除ボタンの処理
        document.getElementById('deauthenticateBtn').addEventListener('click', function() {
            setOwnerStatus(false);
            updateUIByAuthStatus();
            showAuthMessage('認証を解除しました。');
            showTab('auth');
        });

        // フォーム送信時のアニメーション（認証チェック付き）
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const action = this.querySelector('input[name="action"]');
                if (action && (action.value === 'increment' || action.value === 'decrement')) {
                    e.preventDefault();
                    
                    // 認証チェック
                    if (!getOwnerStatus()) {
                        showAuthMessage('オーナー認証が必要です。');
                        showTab('auth');
                        return;
                    }
                    
                    const todaySection = document.querySelector('.today-section');
                    const todayCount = document.querySelector('.today-count');
                    const button = this.querySelector('button');
                    const currentCount = parseInt(todayCount.textContent);
                    const newCount = action.value === 'increment' ? currentCount + 1 : ( (currentCount > 0 )? currentCount - 1 : 0 );

                    // フォームを送信
                    fetch(window.location.href, {
                        method: 'POST',
                        body: new FormData(this)
                    }).then(response => {
                        if (response.ok) {
                            // 数値を更新
                            todayCount.innerHTML = currentCount + ' <span class="count-change">→ ' + newCount + '</span> 回';
                            // アニメーション実行
                            todaySection.classList.add('updated');
                            button.classList.add('updated');
                            // ページをリロード
                            setTimeout(() => {
                                window.location.reload();
                            }, 1500);
                        }
                    });
                } else if (action && action.value === 'save_csv') {
                    // 編集フォームの認証チェック
                    if (!getOwnerStatus()) {
                        e.preventDefault();
                        showAuthMessage('オーナー認証が必要です。');
                        showTab('auth');
                        return;
                    }
                }
            });
        });

        // 初期表示時に認証状態をチェック
        updateUIByAuthStatus();
    </script>
</body>
</html>
