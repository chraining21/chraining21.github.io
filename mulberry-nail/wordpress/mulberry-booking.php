<?php
/**
 * Plugin Name: 霧莓美甲預約系統 Mulberry Booking
 * Description: 美甲工作室的線上預約、價目表與後台管理。作品集範例，店名與資料皆為虛構。
 * Version: 1.0.0
 * Author: mimichan
 * Text Domain: mulberry-booking
 */

if (!defined('ABSPATH')) exit;

const MB_OPT = 'mb_settings';

/* ---------- 設定（店家在後台可以改） ---------- */
function mb_defaults() {
    return [
        'services' => "單色凝膠|900|60|一色到底，最耐看的上班款。\n漸層／貓眼|1200|90|本店招牌霧莓漸層，貓眼磁粉不加價。\n造型設計|1600|120|彩繪、飾品、法式，可帶參考圖。\n手部保養|700|45|去角質、甘皮、滋潤按摩，不上色。\n卸甲重做加購|300|30|他店卸甲另加 NT$100。\n足部單色凝膠|1100|75|夏天必備，含足部基礎保養。",
        'open'     => '11:00',
        'close'    => '20:00',
        'closed'   => '1', // 週一公休（0=日 … 6=六）
    ];
}
function mb_settings() { return wp_parse_args(get_option(MB_OPT, []), mb_defaults()); }

function mb_services() {
    $out = [];
    foreach (preg_split('/\r?\n/', mb_settings()['services']) as $i => $line) {
        $p = array_map('trim', explode('|', $line));
        if (count($p) < 3 || $p[0] === '') continue;
        $out[] = ['id' => $i, 'name' => $p[0], 'price' => (int)$p[1], 'min' => (int)$p[2], 'desc' => $p[3] ?? ''];
    }
    return $out;
}
function mb_service($id) { foreach (mb_services() as $s) if ($s['id'] === (int)$id) return $s; return null; }
function mb_to_min($hm) { [$h, $m] = array_map('intval', explode(':', $hm)); return $h * 60 + $m; }
function mb_hm($m) { return sprintf('%02d:%02d', intdiv($m, 60), $m % 60); }
function mb_money($n) { return 'NT$ ' . number_format($n); }

/* ---------- 預約資料：自訂文章類型 ---------- */
add_action('init', function () {
    register_post_type('mb_booking', [
        'labels' => ['name' => '預約管理', 'singular_name' => '預約', 'all_items' => '所有預約', 'edit_item' => '查看預約', 'search_items' => '搜尋預約', 'not_found' => '還沒有預約'],
        'public' => false, 'show_ui' => true, 'menu_icon' => 'dashicons-calendar-alt', 'menu_position' => 3,
        'supports' => ['title'], 'capability_type' => 'post',
        'capabilities' => ['create_posts' => 'do_not_allow'], 'map_meta_cap' => true,
    ]);
    add_shortcode('mulberry_menu', 'mb_shortcode_menu');
    add_shortcode('mulberry_booking', 'mb_shortcode_booking');
});

function mb_bookings_on($date) {
    $q = get_posts(['post_type' => 'mb_booking', 'numberposts' => -1, 'post_status' => 'publish',
        'meta_query' => [['key' => 'mb_date', 'value' => $date], ['key' => 'mb_status', 'value' => 'cancel', 'compare' => '!=']]]);
    return array_map(fn($p) => ['start' => (int)get_post_meta($p->ID, 'mb_start', true), 'min' => (int)get_post_meta($p->ID, 'mb_min', true)], $q);
}

function mb_slots($date, $min) {
    $s = mb_settings();
    $ts = strtotime($date);
    if (!$ts || (string)wp_date('w', $ts, new DateTimeZone('UTC')) === (string)$s['closed']) return [];
    $taken = mb_bookings_on($date);
    $now = current_datetime();
    $isToday = $date === $now->format('Y-m-d');
    $nowMin = (int)$now->format('G') * 60 + (int)$now->format('i');
    $out = [];
    for ($m = mb_to_min($s['open']); $m + $min <= mb_to_min($s['close']); $m += 30) {
        $ok = !($isToday && $m <= $nowMin);
        foreach ($taken as $b) if ($m < $b['start'] + $b['min'] && $b['start'] < $m + $min) { $ok = false; break; }
        $out[] = ['time' => mb_hm($m), 'start' => $m, 'ok' => $ok];
    }
    return $out;
}

function mb_create_booking($date, $start, $svc, $name, $phone, $note = '', $status = 'new') {
    $id = wp_insert_post(['post_type' => 'mb_booking', 'post_status' => 'publish',
        'post_title' => sprintf('%s %s %s', $date, mb_hm($start), $name)]);
    $code = 'MB' . (2400 + $id);
    foreach (['mb_date' => $date, 'mb_start' => $start, 'mb_min' => $svc['min'], 'mb_service' => $svc['name'],
              'mb_price' => $svc['price'], 'mb_name' => $name, 'mb_phone' => $phone, 'mb_note' => $note,
              'mb_status' => $status, 'mb_code' => $code] as $k => $v) update_post_meta($id, $k, $v);
    return $code;
}

/* ---------- REST API：查空檔、送出預約 ---------- */
add_action('rest_api_init', function () {
    register_rest_route('mulberry/v1', '/slots', ['methods' => 'GET', 'permission_callback' => '__return_true',
        'callback' => function ($r) {
            $svc = mb_service($r['service']);
            $date = sanitize_text_field($r['date']);
            if (!$svc || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return new WP_Error('bad', '請選擇服務與日期', ['status' => 400]);
            return mb_slots($date, $svc['min']);
        }]);
    register_rest_route('mulberry/v1', '/book', ['methods' => 'POST', 'permission_callback' => '__return_true',
        'callback' => function ($r) {
            $svc = mb_service($r['service']);
            $date = sanitize_text_field($r['date']);
            $start = (int)$r['start'];
            $name = sanitize_text_field($r['name']);
            $phone = sanitize_text_field($r['phone']);
            if (!$svc) return new WP_Error('bad', '請先選擇服務項目。', ['status' => 400]);
            if (!$name) return new WP_Error('bad', '請填寫姓名，到店時方便核對。', ['status' => 400]);
            if (!preg_match('/^09\d{2}-?\d{3}-?\d{3}$/', $phone)) return new WP_Error('bad', '手機格式像 0912-345-678，請再確認一次。', ['status' => 400]);
            $slot = array_values(array_filter(mb_slots($date, $svc['min']), fn($s) => $s['start'] === $start));
            if (!$slot || !$slot[0]['ok']) return new WP_Error('taken', '這個時段剛被約走了，請換一個時段。', ['status' => 409]);
            $code = mb_create_booking($date, $start, $svc, $name, $phone, sanitize_text_field($r['note']));
            return ['code' => $code, 'when' => $date . ' ' . mb_hm($start), 'service' => $svc['name']];
        }]);
});

/* ---------- 前台：價目表與預約表單 ---------- */
function mb_shortcode_menu() {
    $h = '<div class="mb-menu">';
    foreach (mb_services() as $s) {
        $h .= sprintf('<div class="mb-item"><h3>%s</h3><span class="mb-price">%s</span><p>%s <span class="mb-dur">約 %d 分鐘</span></p></div>',
            esc_html($s['name']), mb_money($s['price']), esc_html($s['desc']), $s['min']);
    }
    return $h . '</div>';
}

function mb_shortcode_booking() {
    $s = mb_settings();
    $days = [];
    $w = ['日', '一', '二', '三', '四', '五', '六'];
    $today = current_datetime()->setTime(0, 0);
    for ($i = 0; $i < 14; $i++) {
        $d = $today->modify("+$i day");
        $days[] = ['v' => $d->format('Y-m-d'), 'label' => $d->format('n/j'), 'wd' => $w[(int)$d->format('w')], 'closed' => $d->format('w') === (string)$s['closed']];
    }
    $data = ['api' => esc_url_raw(rest_url('mulberry/v1/')), 'services' => mb_services(), 'days' => $days];
    ob_start(); ?>
<div class="mb-book" id="mb-book">
  <div class="mb-step"><div class="mb-st"><span>1</span>選擇服務</div><div class="mb-opts" data-k="svc"></div></div>
  <div class="mb-step"><div class="mb-st"><span>2</span>選擇日期與時段</div><div class="mb-opts mb-days" data-k="day"></div><div class="mb-opts" data-k="slot"></div></div>
  <div class="mb-step"><div class="mb-st"><span>3</span>留下聯絡方式</div>
    <div class="mb-fields">
      <label>姓名<input id="mb-name" autocomplete="name" placeholder="王小美"></label>
      <label>手機<input id="mb-phone" inputmode="tel" placeholder="0912-345-678"></label>
      <label class="mb-full">備註（款式、指甲狀況）<input id="mb-note" placeholder="想做本季的霧莓漸層"></label>
    </div>
    <p class="mb-sum" id="mb-sum"></p>
    <p class="mb-err" id="mb-err" role="alert"></p>
    <button type="button" class="mb-btn" id="mb-submit">送出預約</button>
    <div class="mb-done" id="mb-done" hidden></div>
  </div>
</div>
<script>
(function(){
const D=<?php echo wp_json_encode($data); ?>;const root=document.getElementById('mb-book');
const st={svc:null,day:null,start:null},$=s=>root.querySelector(s),money=n=>'NT$ '+n.toLocaleString('en-US');
function btn(k,v,label,small,dis,on){return `<button type="button" class="mb-opt" data-k="${k}" data-v="${v}" ${dis?'disabled':''} aria-pressed="${on}">${label}${small?`<small>${small}</small>`:''}</button>`}
function svc(){return D.services.find(s=>s.id===st.svc)}
function render(){
  $('[data-k=svc]').innerHTML=D.services.map(s=>btn('svc',s.id,s.name,money(s.price)+' · '+s.min+' 分',false,st.svc===s.id)).join('');
  $('[data-k=day]').innerHTML=D.days.map(d=>btn('day',d.v,d.label,d.closed?'公休':'週'+d.wd,d.closed,st.day===d.v)).join('');
  const s=svc();$('#mb-sum').textContent=s?`${s.name}・${st.day||'選日期'}・${st.start!=null?slotLabel():'選時段'}・預估 ${money(s.price)}`:'';
}
function slotLabel(){const m=st.start;return String(Math.floor(m/60)).padStart(2,'0')+':'+String(m%60).padStart(2,'0')}
async function loadSlots(){
  const box=$('[data-k=slot]');
  if(st.svc==null||!st.day){box.innerHTML='<span class="mb-hint">先選服務和日期，就會出現可預約時段。</span>';return}
  box.innerHTML='<span class="mb-hint">查詢空檔中…</span>';
  const r=await fetch(D.api+'slots?service='+st.svc+'&date='+st.day);const list=await r.json();
  box.innerHTML=Array.isArray(list)&&list.some(x=>x.ok)?list.map(x=>btn('slot',x.start,x.time,'',!x.ok,st.start===x.start)).join(''):'<span class="mb-hint">這天已經約滿了，換一天試試。</span>';
}
root.addEventListener('click',e=>{const b=e.target.closest('.mb-opt');if(!b||b.disabled)return;
  const k=b.dataset.k,v=b.dataset.v;$('#mb-err').textContent='';$('#mb-done').hidden=true;
  if(k==='svc'){st.svc=+v;st.start=null}if(k==='day'){st.day=v;st.start=null}if(k==='slot'){st.start=+v;root.querySelectorAll('[data-k=slot] .mb-opt').forEach(x=>x.setAttribute('aria-pressed',x===b));render();return}
  render();loadSlots();});
$('#mb-submit').addEventListener('click',async()=>{
  if(st.svc==null)return $('#mb-err').textContent='請先選擇服務項目。';
  if(!st.day||st.start==null)return $('#mb-err').textContent='請選擇日期和時段。';
  const body={service:st.svc,date:st.day,start:st.start,name:$('#mb-name').value.trim(),phone:$('#mb-phone').value.trim(),note:$('#mb-note').value.trim()};
  const r=await fetch(D.api+'book',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});const j=await r.json();
  if(!r.ok){$('#mb-err').textContent=j.message||'送出失敗，請再試一次。';if(r.status===409)loadSlots();return}
  $('#mb-done').innerHTML=`<strong>預約成功</strong><span>預約編號 <code>${j.code}</code>，${j.when} 見！</span><span class="mb-hint">店家登入後台的「預約管理」就會看到這筆。</span>`;$('#mb-done').hidden=false;
  st.start=null;['#mb-name','#mb-phone','#mb-note'].forEach(s=>$(s).value='');render();loadSlots();});
render();loadSlots();
})();
</script>
<?php return ob_get_clean();
}

/* ---------- 前台樣式：品牌色覆蓋佈景主題 ---------- */
add_action('wp_enqueue_scripts', function () {
    wp_register_style('mulberry', false);
    wp_enqueue_style('mulberry');
    wp_add_inline_style('mulberry', <<<CSS
body{--wp--preset--color--base:#fbf7f8;--wp--preset--color--contrast:#2b1f2c;--wp--preset--color--accent-1:#a8456f;--wp--preset--color--accent-2:#f3e9ee;--wp--preset--color--accent-4:#7d6f7b;--wp--preset--color--accent-5:#f3e9ee;--wp--preset--color--primary:#a8456f;--wp--preset--color--secondary:#7d6f7b;background:#fbf7f8;color:#2b1f2c}
.wp-element-button,.wp-block-button__link{background:#a8456f!important;color:#fff!important;border-radius:999px!important}
.mb-menu{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:0 48px}
.mb-item{display:grid;grid-template-columns:1fr auto;gap:2px 16px;padding:18px 0;border-bottom:1px dashed #e7dce2}
.mb-item h3{margin:0;font-size:1.1rem}.mb-item p{grid-column:1/-1;margin:0;color:#7d6f7b;font-size:.92rem}
.mb-price{font-size:1.25rem;font-variant-numeric:tabular-nums}.mb-dur{color:#5f8473;font-size:.8rem}
.mb-book{background:#fff;border:1px solid #e7dce2;border-radius:20px;padding:24px;display:grid;gap:22px;color:#2b1f2c}
.mb-st{font-weight:700;display:flex;gap:10px;align-items:center;margin-bottom:10px}.mb-st span{width:26px;height:26px;border-radius:50%;background:#f6dde8;color:#a8456f;display:grid;place-items:center;font-size:.85rem}
.mb-opts{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px}
.mb-days{display:grid;grid-template-columns:repeat(7,minmax(0,1fr))}
.mb-opt{border:1px solid #e7dce2;background:#fff;color:#2b1f2c;border-radius:12px;padding:9px 13px;cursor:pointer;line-height:1.4;font:inherit;font-variant-numeric:tabular-nums}
.mb-opt small{display:block;font-size:.75rem;opacity:.75}.mb-opt[aria-pressed=true]{background:#a8456f;border-color:#a8456f;color:#fff}
.mb-opt:disabled{opacity:.35;text-decoration:line-through;cursor:not-allowed}
.mb-fields{display:grid;grid-template-columns:1fr 1fr;gap:12px}.mb-fields label{display:grid;gap:4px;font-size:.88rem;color:#7d6f7b}.mb-full{grid-column:1/-1}
.mb-fields input{border:1px solid #e7dce2;border-radius:10px;padding:10px 12px;font:inherit}
.mb-btn{background:#a8456f;color:#fff;border:0;border-radius:999px;padding:12px 26px;font:inherit;font-weight:600;cursor:pointer}
.mb-err{color:#b3423a;min-height:1.2em;margin:6px 0}.mb-sum{color:#7d6f7b;margin:12px 0 0}.mb-hint{color:#7d6f7b;font-size:.9rem}
.mb-done{background:#e1ece6;border-radius:14px;padding:14px 16px;display:grid;gap:4px;margin-top:12px}.mb-done strong{color:#5f8473}
.page .wp-block-post-title,.page main .wp-block-group:has(> .wp-block-post-title){display:none}
footer .wp-block-navigation{display:none}
.mb-demo{background:#2b1f2c;color:#f2e9ef;text-align:center;font-size:.85rem;padding:8px 16px}
@media(max-width:640px){.mb-fields{grid-template-columns:1fr}.mb-days{grid-template-columns:repeat(4,minmax(0,1fr))}}
CSS);
});
add_action('wp_body_open', function () { echo '<div class="mb-demo">作品集範例網站：店名、地址與預約資料皆為虛構</div>'; });

/* ---------- 後台：預約列表欄位 ---------- */
add_filter('manage_mb_booking_posts_columns', fn() => ['cb' => '<input type="checkbox">', 'mb_when' => '日期時間', 'mb_name' => '顧客', 'mb_phone' => '手機', 'mb_service' => '服務', 'mb_price' => '金額', 'mb_status' => '狀態']);
add_action('manage_mb_booking_posts_custom_column', function ($col, $id) {
    $m = fn($k) => get_post_meta($id, $k, true);
    switch ($col) {
        case 'mb_when': printf('<strong><a href="%s">%s %s</a></strong><br><small>%s</small>', get_edit_post_link($id), esc_html($m('mb_date')), mb_hm((int)$m('mb_start')), esc_html($m('mb_code'))); break;
        case 'mb_name': echo esc_html($m('mb_name')); if ($m('mb_note')) echo '<br><small>' . esc_html($m('mb_note')) . '</small>'; break;
        case 'mb_phone': echo esc_html($m('mb_phone')); break;
        case 'mb_service': echo esc_html($m('mb_service')); break;
        case 'mb_price': echo mb_money((int)$m('mb_price')); break;
        case 'mb_status':
            $map = ['new' => ['新預約', '#a8456f', '#f6dde8'], 'ok' => ['已確認', '#5f8473', '#e1ece6'], 'cancel' => ['已取消', '#777', '#eee']];
            [$t, $c, $b] = $map[$m('mb_status')] ?? $map['new'];
            printf('<span style="background:%s;color:%s;padding:3px 10px;border-radius:999px;font-size:12px">%s</span>', $b, $c, $t);
    }
}, 10, 2);
add_filter('manage_edit-mb_booking_sortable_columns', fn($c) => $c + ['mb_when' => 'mb_when']);
add_action('pre_get_posts', function ($q) {
    if (!is_admin() || !$q->is_main_query() || $q->get('post_type') !== 'mb_booking') return;
    $q->set('meta_query', ['d' => ['key' => 'mb_date'], 's' => ['key' => 'mb_start', 'type' => 'NUMERIC']]);
    $asc = $q->get('order') === 'desc' ? 'DESC' : 'ASC';
    $q->set('orderby', ['d' => $asc, 's' => $asc]);
});

/* 單筆預約：顯示資料、改狀態 */
add_action('add_meta_boxes_mb_booking', function () {
    add_meta_box('mb_detail', '預約內容', function ($p) {
        $m = fn($k) => get_post_meta($p->ID, $k, true);
        wp_nonce_field('mb_save', 'mb_nonce');
        echo '<table class="form-table">';
        foreach (['預約編號' => $m('mb_code'), '日期' => $m('mb_date'), '時間' => mb_hm((int)$m('mb_start')) . ' – ' . mb_hm((int)$m('mb_start') + (int)$m('mb_min')),
                  '顧客' => $m('mb_name'), '手機' => $m('mb_phone'), '服務' => $m('mb_service'), '金額' => mb_money((int)$m('mb_price')), '備註' => $m('mb_note') ?: '—'] as $k => $v)
            printf('<tr><th>%s</th><td>%s</td></tr>', $k, esc_html($v));
        echo '<tr><th>狀態</th><td><select name="mb_status">';
        foreach (['new' => '新預約', 'ok' => '已確認', 'cancel' => '已取消（釋出時段）'] as $k => $v) printf('<option value="%s"%s>%s</option>', $k, selected($m('mb_status'), $k, false), $v);
        echo '</select></td></tr></table>';
    }, 'mb_booking', 'normal', 'high');
});
add_action('save_post_mb_booking', function ($id) {
    if (!isset($_POST['mb_nonce']) || !wp_verify_nonce($_POST['mb_nonce'], 'mb_save') || !current_user_can('edit_post', $id)) return;
    if (in_array($_POST['mb_status'] ?? '', ['new', 'ok', 'cancel'], true)) update_post_meta($id, 'mb_status', $_POST['mb_status']);
});

/* 控制台小工具：一打開後台就看到本週狀況 */
add_action('wp_dashboard_setup', function () {
    wp_add_dashboard_widget('mb_week', '霧莓美甲・未來 7 天預約', function () {
        $today = current_datetime();
        $from = $today->format('Y-m-d');
        $to = $today->modify('+6 day')->format('Y-m-d');
        $q = get_posts(['post_type' => 'mb_booking', 'numberposts' => -1, 'meta_query' => [
            ['key' => 'mb_date', 'value' => [$from, $to], 'compare' => 'BETWEEN'], ['key' => 'mb_status', 'value' => 'cancel', 'compare' => '!=']]]);
        $rev = array_sum(array_map(fn($p) => (int)get_post_meta($p->ID, 'mb_price', true), $q));
        $todayList = array_filter($q, fn($p) => get_post_meta($p->ID, 'mb_date', true) === $from);
        usort($todayList, fn($a, $b) => get_post_meta($a->ID, 'mb_start', true) <=> get_post_meta($b->ID, 'mb_start', true));
        printf('<p style="display:flex;gap:32px;font-size:14px"><span>預約數<br><b style="font-size:24px">%d</b></span><span>預估營收<br><b style="font-size:24px">%s</b></span></p>', count($q), mb_money($rev));
        echo '<h3>今天</h3>';
        if (!$todayList) echo '<p>今天沒有預約。</p>';
        foreach ($todayList as $p) printf('<p>%s　%s　%s</p>', mb_hm((int)get_post_meta($p->ID, 'mb_start', true)), esc_html(get_post_meta($p->ID, 'mb_name', true)), esc_html(get_post_meta($p->ID, 'mb_service', true)));
        printf('<p><a class="button button-primary" href="%s">查看所有預約</a></p>', admin_url('edit.php?post_type=mb_booking'));
    });
});

/* 設定頁：價目與營業時間，改一次前台價目表和預約表單一起更新 */
add_action('admin_menu', function () {
    add_submenu_page('edit.php?post_type=mb_booking', '價目與營業時間', '價目與營業時間', 'manage_options', 'mb-settings', function () {
        if (isset($_POST['mb_settings_nonce']) && wp_verify_nonce($_POST['mb_settings_nonce'], 'mb_settings')) {
            update_option(MB_OPT, ['services' => sanitize_textarea_field(wp_unslash($_POST['services'])), 'open' => sanitize_text_field($_POST['open']),
                'close' => sanitize_text_field($_POST['close']), 'closed' => sanitize_text_field($_POST['closed'])]);
            echo '<div class="notice notice-success"><p>已儲存，前台價目表與預約表單已同步更新。</p></div>';
        }
        $s = mb_settings();
        $w = ['日', '一', '二', '三', '四', '五', '六'];
        ?>
        <div class="wrap"><h1>價目與營業時間</h1>
        <form method="post"><?php wp_nonce_field('mb_settings', 'mb_settings_nonce'); ?>
        <table class="form-table">
          <tr><th>服務項目</th><td><textarea name="services" rows="8" class="large-text code"><?php echo esc_textarea($s['services']); ?></textarea>
            <p class="description">一行一個服務，格式：名稱|價格|分鐘|說明。例如「單色凝膠|900|60|一色到底」。</p></td></tr>
          <tr><th>營業時間</th><td><input name="open" value="<?php echo esc_attr($s['open']); ?>" size="6"> 到 <input name="close" value="<?php echo esc_attr($s['close']); ?>" size="6"></td></tr>
          <tr><th>公休日</th><td><select name="closed"><?php foreach ($w as $i => $d) printf('<option value="%d"%s>週%s</option>', $i, selected((string)$s['closed'], (string)$i, false), $d); ?></select></td></tr>
        </table><?php submit_button('儲存'); ?></form></div>
        <?php
    });
});

/* ---------- 啟用時建立示範頁面與預約 ---------- */
register_activation_hook(__FILE__, 'mb_setup_demo');
function mb_setup_demo() {
    if (get_option('mb_demo_done')) return;
    add_option(MB_OPT, mb_defaults());
    update_option('blogname', '霧莓美甲 Mulberry Nail');
    update_option('blogdescription', '台北中山・一人美甲工作室');
    $home = <<<HTML
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"64px","bottom":"48px"}},"color":{"background":"#f3e9ee"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-background" style="background-color:#f3e9ee;padding-top:64px;padding-bottom:48px">
<!-- wp:paragraph {"style":{"typography":{"letterSpacing":"0.18em","fontSize":"0.8rem"},"color":{"text":"#a8456f"}}} -->
<p class="has-text-color" style="color:#a8456f;font-size:0.8rem;letter-spacing:0.18em">台北・中山 一人美甲工作室</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">不用再私訊等回覆，30 秒就約好妳的指尖時光</h1>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>選服務、挑時段、留下名字，馬上收到預約編號。被約走的時段會自動關閉，不會撞單。</p>
<!-- /wp:paragraph -->
<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/booking/">看可預約時段</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->
<!-- wp:heading -->
<h2 class="wp-block-heading">價目表</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>價格皆含基礎修型與甘皮處理。延甲、彩繪依指數另計。</p>
<!-- /wp:paragraph -->
<!-- wp:shortcode -->
[mulberry_menu]
<!-- /wp:shortcode -->
<!-- wp:heading -->
<h2 class="wp-block-heading">交通與營業時間</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>台北市中山區赤峰街 ○○ 號 2 樓，捷運中山站 4 號出口步行 5 分鐘。<br>週二至週日 11:00 – 20:00，週一公休。</p>
<!-- /wp:paragraph -->
HTML;
    $home_id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => '首頁', 'post_content' => $home, 'menu_order' => 1]);
    wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => '線上預約', 'post_name' => 'booking', 'menu_order' => 2,
        'post_content' => "<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">線上預約</h1>\n<!-- /wp:heading -->\n<!-- wp:paragraph -->\n<p>週一公休。營業時間 11:00 – 20:00，最後入座時間依服務長度自動計算。</p>\n<!-- /wp:paragraph -->\n<!-- wp:shortcode -->\n[mulberry_booking]\n<!-- /wp:shortcode -->"]);
    wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => '預約須知', 'menu_order' => 3,
        'post_content' => "<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">預約須知</h1>\n<!-- /wp:heading -->\n<!-- wp:list -->\n<ul><li>遲到 15 分鐘以上將調整服務內容。</li><li>需改期請於前一天告知。</li><li>他店卸甲另加 NT\$100。</li></ul>\n<!-- /wp:list -->"]);
    update_option('show_on_front', 'page');
    update_option('page_on_front', $home_id);
    foreach (get_posts(['post_type' => ['post', 'page'], 'post_status' => 'any', 'numberposts' => -1]) as $p)
        if (in_array($p->post_name, ['hello-world', 'sample-page'], true)) wp_delete_post($p->ID, true);

    $svcs = mb_services();
    $plan = [[0, 1, 13 * 60, '林小姐'], [0, 0, 16 * 60, '陳小姐'], [1, 2, 11 * 60 + 30, 'Amber'], [1, 3, 15 * 60, '黃小姐'],
             [2, 0, 12 * 60, '張小姐'], [2, 1, 17 * 60, 'Yuki'], [3, 5, 14 * 60, '吳小姐'], [4, 2, 13 * 60, '林小姐']];
    $closed = (string)mb_settings()['closed'];
    foreach ($plan as $i => [$off, $si, $start, $name]) {
        $d = current_datetime()->modify("+$off day");
        while ($d->format('w') === $closed) $d = $d->modify('+1 day');
        mb_create_booking($d->format('Y-m-d'), $start, $svcs[$si], $name, sprintf('09%02d-***-%03d', 11 + $i * 7, 100 + $i * 37), '', 'ok');
    }
    update_option('mb_demo_done', 1);
}
