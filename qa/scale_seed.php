<?php
// Scale lab, step 2: bulk data for the 120 shops from plan.json (catalogs, customers, staff, history). Scale lab only.
require __DIR__.'/../backend/vendor/autoload.php';
$app = require __DIR__.'/../backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (! str_contains(config('database.connections.mysql.database'), 'neotalab_qa_scale')) { exit("refusing: not the scale lab\n"); }
mt_srand(41);
$plan = json_decode(file_get_contents(__DIR__.'/.lab/scale/plan.json'), true);
$now = now()->toDateTimeString();

$bank = [
 'Restaurant / Café' => ['Chicken Shawarma','Beef Burger','Margherita Pizza','Falafel Wrap','Hummus Plate','Fattoush Salad','Grilled Kebab','Mix Grill','French Fries','Lemonade','Turkish Coffee','Baklava'],
 'Bakery / Sweets' => ['Croissant','Baguette','Chocolate Cake','Cheese Manousheh','Zaatar Manousheh','Cookies Box','Birthday Cake','Cupcake','Donut','Muffin','Sourdough Loaf','Maamoul'],
 'Butcher / Fruits & Vegetables' => ['Beef Mince 1kg','Lamb Chops','Chicken Breast','Tomatoes 1kg','Cucumbers 1kg','Apples 1kg','Bananas 1kg','Potatoes 1kg','Onions 1kg','Lemons 1kg','Parsley Bunch','Oranges 1kg'],
 'Pharmacy' => ['Panadol 500mg','Vitamin C 1000mg','Cough Syrup','Band-Aid Pack','Baby Diapers','Face Mask Box','Hand Sanitizer','Ibuprofen 400mg','Multivitamin','Eye Drops','Thermometer','Antacid Tablets'],
 'Supermarket / Grocery' => ['Basmati Rice','Olive Oil','Fresh Milk','Eggs Tray','White Bread','Tomatoes','Cucumbers','Orange Juice','Pasta','Sugar','Tea Bags','Laundry Detergent'],
 'Electronics / Phones' => ['USB-C Cable','Phone Case','Screen Protector','Wireless Earbuds','Power Bank','Wall Charger','Bluetooth Speaker','Smart Watch','HDMI Cable','Memory Card','Car Charger','Laptop Mouse'],
 'Water / Gas delivery' => ['Water Bottle 19L','Gas Cylinder','Mineral Water Pack','Water Dispenser','Cup Pack','Gas Regulator','Gas Hose','Bottled Water 1.5L'],
 'Pet Shop' => ['Dog Food 5kg','Cat Food 2kg','Cat Litter','Dog Leash','Pet Shampoo','Fish Food','Bird Seed','Chew Toy','Pet Bed','Cat Scratcher','Dog Collar','Pet Carrier'],
 'Other' => ['Oil Change','Car Wash','Tire Rotation','Battery Check','Brake Inspection','Wheel Alignment','Engine Tune-up','AC Service','Window Tint','Detailing'],
 'Bakery' => ['Croissant','Baguette','Chocolate Cake','Cheese Manousheh','Zaatar Manousheh','Cookies Box','Birthday Cake','Cupcake','Donut','Muffin','Sourdough Loaf','Maamoul'],
 'Flowers' => ['Red Roses Bouquet','Tulips Bunch','Sunflower Bouquet','Orchid Plant','Mixed Bouquet','Lilies','Wedding Arrangement','Gift Basket','Succulent Pot','Carnations'],
 'Clothing' => ['Cotton T-Shirt','Denim Jeans','Summer Dress','Leather Belt','Winter Jacket','Sneakers','Wool Scarf','Polo Shirt','Hoodie','Socks Pack','Cap','Gloves'],
 'Books' => ['Notebook A5','Ballpoint Pens Pack','Arabic Novel','French Dictionary','Kids Story Book','School Bag','Highlighters','Sketchbook','Calculator','Folder Pack'],
 'Hardware' => ['Hammer','Screwdriver Set','Paint 4L','Wall Plug Pack','Drill Bit Set','Measuring Tape','LED Bulb','Extension Cord','Pliers','Door Lock','Wood Glue','Sandpaper'],
 'Cosmetics' => ['Lipstick','Foundation','Mascara','Perfume 50ml','Nail Polish','Face Cream','Shampoo','Body Lotion','Eyeliner','Hair Mask','Sunscreen','Lip Balm'],
 'Jewelry' => ['Silver Ring','Gold Chain','Pearl Earrings','Charm Bracelet','Engagement Ring','Pendant','Watch Strap','Anklet','Brooch','Cufflinks'],
 'Furniture' => ['Office Chair','Dining Table','Bookshelf','Sofa Cushion','Bed Frame','Coffee Table','Wardrobe','TV Stand','Desk Lamp','Shoe Rack'],
 'Toys' => ['Lego Set','Puzzle 500pc','Toy Car','Doll House','Board Game','Soft Bear','Play Dough','Water Gun','Kite','Remote Car'],
 'Car parts' => ['Engine Oil 5L','Brake Pads','Air Filter','Spark Plugs','Wiper Blades','Car Battery','Coolant','Headlight Bulb','Floor Mats','Seat Cover'],
];
$variants = ['','Small','Large','Family','Premium','Mini','Classic','Deluxe','XL','Pack of 3','Plus','Lite','Organic','Special','Value'];
$arNames = ['شاورما','برغر','بيتزا','فلافل','حمص','فتوش','كباب','عصير','قهوة','حلويات','خبز','جبنة','زيت','أرز','سكر','شاي','منظف','كابل','غطاء','سماعات'];
$frNames = ['Poulet','Burger','Pizza','Falafel','Houmous','Salade','Brochette','Jus','Café','Gâteau','Pain','Fromage','Huile','Riz','Sucre','Thé','Lessive','Câble','Étui','Écouteurs'];

$hash = Illuminate\Support\Facades\Hash::make('123456');
$totals = ['products' => 0, 'categories' => 0, 'customers' => 0, 'staff' => 0, 'orders' => 0, 'items' => 0];
$custPhones = [];
for ($c = 0; $c < 1500; $c++) { $custPhones[] = '+9613'.str_pad((string) (100000 + $c * 37 % 899999), 6, '0', STR_PAD_LEFT); }
$custPhones = array_values(array_unique($custPhones));
$perShopCustomers = (int) ceil(1500 / count($plan));
$ci = 0; $staffN = 0;
foreach ($plan as $si => $shop) {
    $mid = $shop['id']; $type = $shop['type']; $loc = $shop['locale'];
    DB::table('merchants')->where('id', $mid)->update(['locale' => $loc, 'trial_ends_at' => now()->addYears(2), 'status' => 'active', 'updated_at' => $now]);
    $names = $bank[$type] ?? $bank['Other'];
    // categories
    $ncat = max(3, min(8, (int) ceil($shop['products'] / 40) + 2)); $cats = [];
    for ($k = 0; $k < $ncat; $k++) {
        $cid = (string) Str::uuid(); $cats[] = $cid;
        DB::table('categories')->insert(['id' => $cid, 'merchant_id' => $mid, 'name' => "Section ".($k + 1), 'is_active' => 1, 'sort_order' => $k, 'created_at' => $now, 'updated_at' => $now]);
        $totals['categories']++;
    }
    // products: base items x variants, price fixed per item so checks can compare against the database
    $rows = []; $prods = [];
    for ($p = 0; $p < $shop['products']; $p++) {
        $base = $names[$p % count($names)]; $var = $variants[intdiv($p, count($names)) % count($variants)];
        $label = trim($base.' '.$var); if (intdiv($p, count($names) * count($variants)) > 0) { $label .= ' '.(intdiv($p, count($names) * count($variants)) + 1); }
        $price = round(1 + (crc32($shop['key'].$label) % 4500) / 100, 2);
        $pid = (string) Str::uuid();
        $prods[] = ['id' => $pid, 'price' => $price, 'name' => $label];
        $rows[] = ['id' => $pid, 'merchant_id' => $mid, 'category_id' => $cats[$p % $ncat], 'name' => $label,
            'name_ar' => $loc === 'ar' ? ($arNames[$p % count($arNames)].' '.($p + 1)) : null, 'name_fr' => $loc === 'fr' ? ($frNames[$p % count($frNames)].' '.($p + 1)) : null,
            'sku' => strtoupper(substr($shop['key'], 2)).'-'.str_pad((string) $p, 4, '0', STR_PAD_LEFT), 'price' => $price, 'unit' => 'piece',
            'is_active' => 1, 'is_available' => 1,
            'sort_order' => $p, 'created_at' => $now, 'updated_at' => $now];
    }
    foreach (array_chunk($rows, 500) as $chunk) { DB::table('products')->insert($chunk); }
    $totals['products'] += count($rows);
    // customers
    $custIds = [];
    for ($k = 0, $quota = 12 + ($si < 60 ? 1 : 0); $k < $quota && $ci < 1500; $k++, $ci++) {
        $id = (string) Str::uuid(); $custIds[] = $id;
        DB::table('customers')->insert(['id' => $id, 'merchant_id' => $mid, 'phone' => $custPhones[$ci], 'name' => "Customer $ci", 'locale' => $loc, 'is_blocked' => 0, 'total_orders' => 0, 'total_spent' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $totals['customers']++;
    }
    // some phones are customers of two shops (same person, two shops): the data must never mix
    if ($si % 4 === 0 && $si > 0) {
        $shared = $custPhones[($si * 7) % 1500];
        if (! DB::table('customers')->where('merchant_id', $mid)->where('phone', $shared)->exists()) {
            $id = (string) Str::uuid(); $custIds[] = $id;
            DB::table('customers')->insert(['id' => $id, 'merchant_id' => $mid, 'phone' => $shared, 'name' => 'Shared person', 'locale' => $loc, 'is_blocked' => 0, 'total_orders' => 0, 'total_spent' => 0, 'created_at' => $now, 'updated_at' => $now]);
        }
    }
    // staff
    for ($k = 0; $k < 5; $k++) {
        $staffN++; DB::table('drivers')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $mid, 'name' => "Staff $staffN", 'role' => $k === 0 ? 'staff' : 'driver', 'phone' => '+9618'.str_pad((string) $staffN, 6, '0', STR_PAD_LEFT),
            'whatsapp_number' => '+9618'.str_pad((string) $staffN, 6, '0', STR_PAD_LEFT), 'password' => $hash, 'status' => 'off_duty', 'availability' => 'available', 'cash_on_hand' => 0, 'total_deliveries' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $totals['staff']++;
    }
    // history: about 417 orders per shop (50,000 in all) over the last 90 days
    $nOrders = (int) round(50000 / count($plan)); $orderRows = []; $itemRows = [];
    for ($o = 1; $o <= $nOrders; $o++) {
        $oid = (string) Str::uuid(); $sub = 0; $k = 1 + ($o % 4);
        $when = now()->subMinutes(mt_rand(60, 90 * 24 * 60))->toDateTimeString();
        for ($j = 0; $j < $k; $j++) {
            $pr = $prods[($o * 7 + $j * 13) % count($prods)]; $qty = 1 + (($o + $j) % 3); $tot = round($pr['price'] * $qty, 2); $sub += $tot;
            $itemRows[] = ['id' => (string) Str::uuid(), 'merchant_id' => $mid, 'order_id' => $oid, 'product_id' => $pr['id'], 'product_name' => $pr['name'], 'quantity' => $qty, 'unit' => 'piece', 'unit_price' => $pr['price'], 'item_total' => $tot, 'created_at' => $when, 'updated_at' => $when];
        }
        $fee = $o % 3 === 0 ? 0 : 2.00; $status = $o % 15 === 0 ? 'cancelled' : 'delivered';
        $orderRows[] = ['id' => $oid, 'merchant_id' => $mid, 'number' => $o, 'customer_id' => $custIds[$o % count($custIds)], 'status' => $status, 'fulfillment' => $fee > 0 ? 'delivery' : 'pickup',
            'subtotal' => round($sub, 2), 'delivery_fee' => $fee, 'total' => round($sub + $fee, 2), 'payment_method' => 'cash', 'payment_status' => 'cash', 'created_at' => $when, 'updated_at' => $when,
            'delivered_at' => $status === 'delivered' ? $when : null, 'cancelled_at' => $status === 'cancelled' ? $when : null];
        if (count($orderRows) >= 400) { DB::table('orders')->insert($orderRows); DB::table('order_items')->insert($itemRows); $totals['orders'] += count($orderRows); $totals['items'] += count($itemRows); $orderRows = []; $itemRows = []; }
    }
    if ($orderRows) { DB::table('orders')->insert($orderRows); DB::table('order_items')->insert($itemRows); $totals['orders'] += count($orderRows); $totals['items'] += count($itemRows); }
    if ($si % 20 === 0) { fwrite(STDERR, "shop $si done ".json_encode($totals)."\n"); }
}
echo json_encode($totals)."\n";
