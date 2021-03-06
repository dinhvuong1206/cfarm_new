<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use DB;
use Cart;
use Auth;

class OrderController extends Controller
{
	/**
	  * An Order Status: Order Submitted -> Processing -> Confirmed -> Assigned   ----> Picked ------> Delievered
	  *                  (Đã nhập đơn hàng) (Đang xử lý)  (Xác nhận)  (Đã phân công)   (Đã lấy hàng)   (Đã giao hàng)
	  * In case deliver on Wednesday & Saturday:
	  */

	/**
	 *addOrder
	 *
	 * Insert an order for customer into DB
	 * @param $cart ($product_id, $farmer_id, $product_id, $quantity)
	 *        $customer_id: customer to be inserted for
	 * @return array of products in its categories 
	 */

  public function moveOrder($order_id, $week_num)
  {
    $delivery_date = DB::select('SELECT DATE_ADD(`delivery_date`, INTERVAL 7*? DAY) "next_delivery_date", `delivery_date` "delivery_date" FROM `g_orders` WHERE `order_id` = ?', [$week_num, $order_id]);
    if(count($delivery_date) < 1) {
      $msg['error'] = 0;
      $msg['status'] = "Mã Order ".$order_id." không tồn tại trong hệ thống.";
      return response()->json($msg);
    }
    $next_delivery_date = $delivery_date[0]->next_delivery_date;
    $delivery_date = $delivery_date[0]->delivery_date;
    if(Auth::check()) {
        DB::statement('UPDATE `trading` AS t, `m_packages` AS m, `m_orders` AS mo, `products` AS p, `g_orders` g 
                          SET t.`sold` = ROUND(t.`sold`,2) - ROUND(m.`quantity`* mo.`quantity`, 2)
                        WHERE t.`farmer_id` = m.`farmer_id`
                          AND t.`product_id` = m.`product_id` 
                          AND p.`id` = mo.`product_id`
                          AND p.`category` = 0
                          AND m.`delivery_date` = t.`delivery_date`
                          AND m.`package_id` = p.`id`
                          AND t.`delivery_date` = g.`delivery_date`
                          AND g.`order_id` = mo.`order_id`
                          AND mo.`order_id` = ?', [$order_id]);

      $update = DB::statement('UPDATE `trading` tr, `m_orders` m, `g_orders` g
                                  SET tr.`sold` = ROUND(tr.`sold`,2) - ROUND(m.`quantity`, 2)
                                WHERE m.`order_id` = g.`order_id`
                                  AND g.`order_id` = ?
                                  AND g.`delivery_date` = tr.`delivery_date`
                                  AND tr.`product_id` = m.`product_id`
                                  AND tr.`farmer_id` = m.`farmer_id`', [$order_id]);

        $update =  DB::statement('UPDATE `g_orders` 
                                     SET `delivery_date` = ?
                                   WHERE `order_id` = ?', [$next_delivery_date, $order_id]);
          
        $update = DB::statement('UPDATE `trading` tr, `m_orders` m
                                    SET tr.`sold` = ROUND(tr.`sold`, 2) + ROUND(m.`quantity`, 2)
                                  WHERE m.`order_id` = ?
                                    AND tr.`product_id` = m.`product_id`
                                    AND tr.`farmer_id` = m.`farmer_id`
                                    AND tr.`delivery_date` = ?', [$order_id, $next_delivery_date]);

        DB::statement('UPDATE `trading` AS t, `m_packages` AS m, `m_orders` AS mo, `products` AS p 
                          SET t.`sold` = ROUND(t.`sold`, 2) + ROUND(m.`quantity`* mo.`quantity`, 2)
                        WHERE t.`farmer_id` = m.`farmer_id`
                          AND t.`product_id` = m.`product_id` 
                          AND p.`id` = mo.`product_id`
                          AND p.`category` = 0
                          AND m.`delivery_date` = t.`delivery_date`
                          AND m.`package_id` = p.`id`
                          AND t.`delivery_date` = ?
                          AND mo.`order_id` = ?', [$next_delivery_date, $order_id]);
        if($update){
            return redirect()->back();
        }else{
            return "Cập nhật không thành công";
        }

    }
    else {
      return redirect()->back();
    } 
  }

  public function cancelOrder($order_id)
  {
    $cancelStatus = 8;
    if(Auth::check()) {
      $user = Auth::user();
          $customer_id = $user->connected_id;
      if(strcmp($user->account_type, "Admin") == 0){

              DB::statement('UPDATE `trading` AS t, `m_packages` AS m, `m_orders` AS mo, `products` AS p , `g_orders` g
                                SET t.`sold` = ROUND(t.`sold`, 2) - ROUND(m.`quantity`* mo.`quantity`, 2)
                              WHERE t.`farmer_id` = m.`farmer_id`
                                AND t.`product_id` = m.`product_id` 
                                AND p.`id` = mo.`product_id`
                                AND p.`category` = 0
                                AND m.`delivery_date` = t.`delivery_date`
                                AND t.`delivery_date` = g.`delivery_date`
                                AND g.`order_id` = mo.`order_id`
                                AND g.`status` != 8
                                AND m.`package_id` = p.`id`
                                AND mo.`order_id` = ?', [$order_id]);

          
            DB::statement('UPDATE `g_orders` AS g, `m_orders` AS m, `trading` t
                              SET t.`sold` = ROUND(t.`sold`, 2) - ROUND(m.`quantity`, 2),
                                  g.`status` = 8
                            WHERE g.`status` != 8
                              AND m.`order_id` = g.`order_id`
                              AND m.`farmer_id` = t.`farmer_id`
                              AND m.`product_id` = t.`product_id`
                              AND t.`delivery_date` = g.`delivery_date`
                              AND g.`order_id` = ?', [$order_id]);

        }
        else {
          return redirect()->back();
        }
      }
      else {
        return redirect()->back();
      }

  }

	public function addOrder(Request $request)
	{
		$data = $request->data;
		$phone = trim($data["sdt"]);
    $email = trim($data["email"]);
    $name  = trim($data["ten"]);
    $address = trim($data["diaChi"]);
    $district = $data["selectQuan"];
    $payment = $data["thanhToan"];
    $promotion_code = $data["maGiamGia"];
    $note = $data["note"];
    
    $items = Cart::content();
    $orderPossible = 1;
    $counter = 1;
    $total = 0;
    $promotion = 0;

    

		foreach ($items as $item) {
			$product_id = $item->id;
			$farmer_id = $item->options["farmer_id"];
			$price = $item->price;
			$qty = $item->qty;
			
			//receive numbers and check if quantity_left is >= order quantity
			$numbers = DB::select('SELECT tr.`unit` "unit", tr.`price_farmer` "price_farmer", tr.`unit_quantity` "unit_quantity", ROUND(tr.`capacity` - tr.`sold`, 2) AS "quantity_left", tr.`delivery_date` "delivery_date", p.`category` AS "category"
                               FROM `products` p, `trading` tr 
                              WHERE p.`id` = tr.`product_id` 
                                AND tr.`status` = 1 
                                AND tr.`farmer_id` = ? 
                                AND p.`id` = ?', [$farmer_id, $product_id]);

      if($numbers[0]->category == 0){
        $m_package_price =  DB::select('SELECT SUM(ROUND((0.95 * tr.`price`*m.`quantity`)/tr.`unit_quantity`)) AS "package_price"
                                          FROM `m_packages` m, `trading` tr
                                         WHERE `package_id` = ?
                                           AND m.`delivery_date` = ?
                                           AND m.`product_id` = tr.`product_id`
                                           AND m.`farmer_id` = tr.`farmer_id`
                                           AND m.`delivery_date` = tr.`delivery_date` ', 
                                           [$product_id, $numbers[0]->delivery_date]
                                      );
        $total = $total + ($m_package_price[0]->package_price * $qty);
      }
      else {
        $total = $total + ($price * $qty);
      }
			
			if($numbers[0]->quantity_left < round($qty * $numbers[0]->unit_quantity, 2) )
			{
				$item->options["error"] = 1;
				$orderPossible = 0;
				Cart::update($item->rowId, $item->options["error"]);
			}
		}
    if($orderPossible == 0){
     	$msg['Cart'] = Cart::content();
   		return response()->json($msg); 
    }


    $delivery_date = $numbers[0]->delivery_date;        
    $shipping_cost = DB::select('SELECT `shipping_cost`, `name` FROM `district` WHERE `id` = ?', [$district]);

    //Be careful of reassignment of $shipping_cost to itself
    $district_name = $shipping_cost[0]->name;
    $shipping_cost = $shipping_cost[0]->shipping_cost;
    $msg['shipping_cost'] = $shipping_cost;

    $msg['promotion'] = $promotion;
    $balance = 0;

    if(Auth::check()) {
     	$user = Auth::user();
     	// $customer_id = $user->connected_id;
      // $balance = $user->balance;

     	$account_email = $user->email;
     		//check if data exist in db (email and phone)
     	$customer_id = DB::select('SELECT `id`, `balance` FROM `customers` WHERE `phone` = ?', [$phone]);
     	//if not yet in db, create customer into db
     	if(count($customer_id) < 1) {
     		DB::insert('INSERT INTO customers(`name`, `phone`, `email`, `address`, `district`, `created_at`) VALUES(?,?,?,?,?, CURRENT_TIMESTAMP)', [$name, $phone, $email, $address, $district]);
     		$customer_id = DB::select('SELECT `id`, `balance` FROM `customers` WHERE `phone` = ?', [$phone]);
     	}
      $balance = $customer_id[0]->balance;
     	$customer_id = $customer_id[0]->id;
      if(!is_numeric($user->connected_id)) {
        DB::statement('UPDATE `users` SET `account_type` = "Customer", `connected_id` = ? WHERE `email` = ?', [$customer_id, $user->email]);     
      }
      else {
        $customer_id = $user->connected_id;
      }
 
    }
    else 
    {
     	//check if data exist in db (email and phone)
     	$customer_id = DB::select('SELECT `id`, `balance` FROM `customers` WHERE `phone` = ?', [$phone]);
     	//if not yet in db, create customer into db
     	if(count($customer_id) < 1) {
     		DB::insert('INSERT INTO customers(`name`, `phone`, `email`, `address`, `district`, `created_at`) VALUES(?,?,?,?,?, CURRENT_TIMESTAMP)', [$name, $phone, $email, $address, $district]);
     		$customer_id = DB::select('SELECT `id`, `balance` FROM `customers` WHERE `phone` = ?', [$phone]);
     	}
      $balance = $customer_id[0]->balance;
     	$customer_id = $customer_id[0]->id;
    }
// Create Order_id and return after adding the order successfully.
    //#34256789
    if($total >= 500000) {
     	$shipping_cost = 0;//free ship
    }
    $address = $address." ".$district_name;

    $discount_amount = ROUND(($promotion * $total)/100, 0);
    $total = $total - $discount_amount + $shipping_cost;
    $deposit = 0;

    if($balance > 0){
      if($balance >= $total){
        $deposit = $total;
      }
      else {
        $deposit = $balance;
      }
      $balance = $balance - $deposit;
      DB::statement('UPDATE `customers` SET `balance` = ? WHERE `phone` = ?', [$balance, $phone]);
    }
    $order_id = DB::select('SELECT `order_id` "order_id" FROM `uniqueids` WHERE `id` = 1');
    $order_id = $order_id[0]->order_id;
    DB::statement('UPDATE `uniqueids` SET `order_id` = `order_id`+1 WHERE `id` = 1');

    DB::insert('INSERT INTO g_orders(`order_id`, `customer_id`, `payment`, `promotion_code`, `delivery_address`, `delivery_phone`, `delivery_district`, `shipping_cost`, `order_total`, `total`, `discount_amount`, `created_at`, `delivery_date`, `note`, `delivery_name`, `deposit`) VALUES(?,?,?,?,?,?,?,?,?,?,?, CURRENT_TIMESTAMP,?,?,?,?)', [$order_id, $customer_id, $payment, $promotion_code, $address, $phone, $district, $shipping_cost, $total, $total, $discount_amount, $delivery_date, $note, $name, $deposit]);

 		// $items = Cart::content();
		$msg['order_id'] = $order_id;

		foreach ($items as $item) {
			$product_id = $item->id;
			$farmer_id = $item->options["farmer_id"];
			$price = $item->price;
			$qty = $item->qty;
			
			//receive numbers and check if quantity_left is >= order quantity
		$numbers = DB::select('SELECT tr.`unit` "unit", tr.`price_farmer` "price_farmer", tr.`unit_quantity` "unit_quantity", 
                                   ROUND(tr.`capacity` - tr.`sold`, 2) AS "quantity_left", p.`category` AS "category" 
                              FROM `products` p, `trading` tr 
                             WHERE p.`id` = tr.`product_id` 
                               AND tr.`status` = 1 
                               AND tr.`farmer_id` = ? 
                               AND p.`id` = ?', [$farmer_id, $product_id]);
			
			if($numbers[0]->quantity_left < round($qty * $numbers[0]->unit_quantity, 2) )
			{
				$item->options["error"] = 1;
				Cart::update($item->rowId, $item->options["error"]);
			}
			else{
				$quantity = round($qty * $numbers[0]->unit_quantity, 2);
				$price_farmer = $qty * $numbers[0]->price_farmer;
				$unit = $numbers[0]->unit;
				$category = $numbers[0]->category;

        //Proccess the elements in case package is order
        if($category == 0) //package
        {
          DB::statement('UPDATE `trading` AS t, `m_packages` AS m 
                            SET t.`sold` = ROUND(t.`sold` + m.`quantity`*?, 2)
                          WHERE t.`farmer_id` = m.`farmer_id`
                            AND t.`status` = 1
                            AND m.`delivery_date` = t.`delivery_date`
                            AND t.`product_id` = m.`product_id` 
                            AND m.`package_id` = ?', [$quantity, $product_id]);

          DB::insert('INSERT INTO m_orders(`order_id`, `farmer_id`, `product_id`, `quantity`, `order_quantity`, 
                                           `unit`, `price`, `price_farmer`, `order_type`) 
                      SELECT ?, m.`farmer_id`, m.`product_id`, m.`quantity`, m.`quantity`, m.`unit`, 
                             ROUND(0.95*(tr.`price` * m.`quantity`)/tr.`unit_quantity`), ROUND((tr.`price_farmer` * m.`quantity`)/tr.`unit_quantity`), 4
                        FROM `m_packages` m, `trading` tr
                       WHERE m.`package_id` = ? 
                         AND m.`delivery_date` = tr.`delivery_date`
                         AND tr.`product_id` = m.`product_id`
                         AND tr.`farmer_id` = m.`farmer_id` 
                         AND m.`delivery_date` = ?', [$order_id, $product_id, $delivery_date]);
        }
        else {
          $m_order = DB::insert('INSERT INTO m_orders(`order_id`, `farmer_id`, `product_id`, `order_quantity`, `quantity`, `unit`, `price`, `price_farmer`) VALUES(?,?,?,?,?,?,?,?)', [$order_id, $farmer_id, $product_id, $quantity, $quantity, $unit, $price * $qty, $price_farmer]);

        }


       	//update trading table
      	DB::statement('UPDATE `trading` SET `sold` = `sold` + ? WHERE `status` = 1 AND `farmer_id` = ? AND `product_id` = ?', [$quantity, $farmer_id, $product_id]);

			}     	
    }
	Cart::destroy();
 	$msg['Cart'] = Cart::content();
  return response()->json($msg);
}
	
	public function rateOrder(Request $request)
	{
        return redirect()->back();
	}


	/**
	 *cancelOrder
	 *
	 * Insert an order for customer into DB
	 * @param $cart ($product_id, $farmer_id, $product_id, $quantity)
	 *        $customer_id: customer to be inserted for
	 * @return array of products in its categories 
	 */

	public function orderItems($order_id)
	{
		if(Auth::check()) {
			$products = DB::select('SELECT f.`name` "farmer_name", f.`id` "farmer_id", p.`name` "product_name", 
                                     p.`id` "product_id", p.`category` "category", m.`quantity` "quantity", m.`order_quantity` "order_quantity", m.`unit` "unit", m.`price` "price", m.`order_type` "order_type"
                                FROM `m_orders` m, `products` p, `farmers` f 
                               WHERE p.`id` = m.`product_id` 
                                 AND f.`id` = m.`farmer_id` 
                                 AND `order_id` = ? 
                            ORDER BY m.`order_type` ASC, p.`category` DESC', [$order_id]);

      for($x=0; $x<count($products); $x++) {
          if($products[$x]->category==0){
              $products[$x]->inPack = $products[$x]->product_id;
              $products[$x]->order = $order_id;
              $items =  DB::select('SELECT f.`name` "farmer_name", f.`id` "farmer_id", p.`name` "product_name", 
                                            p.`id` "product_id", m.`quantity` "quantity", "" AS "order_quantity", m.`unit` "unit", m.`price` "price", p.`thumbnail` "product_thumbnail" 
                                      FROM `m_packages` m, `products` p, `farmers` f, `g_orders` g
                                     WHERE p.`id` = m.`product_id` 
                                       AND f.`id` = m.`farmer_id` 
                                       AND m.`delivery_date` = g.`delivery_date`
                                       AND g.`order_id` = ?
                                       AND m.`package_id` = ?',[$order_id, $products[$x]->product_id]);
              $products[$x]->items = $items;
          }
      }
    		return $products;
    	}
    	else {
    		return redirect()->back();
    	}

	}


    public function productInOrder($delivery_date)
    {
      
    }

    public function addItemAdmin(Request $request)
    {
      /*
       * order_type: 0: normal; 1:gift; 2:price_farmer; 3:wholesale; 4: gói
       **/
        if(Auth::check()){
            $data = $request->data;
            $order_id = $data["order_id"];

            $m_orders = $data["ItemsUpload"];

            $g_orders = DB::select('SELECT g.`total`, g.`shipping_cost` "shipping_cost", g.`delivery_date`, 
                                           d.`shipping_cost` "shipping_cost_ex"
                                      FROM `g_orders` g, `district` d
                                     WHERE `order_id` = ?
                                       AND g.`delivery_district` = d.`id`', [$order_id]);

            if(count($g_orders) < 1){
              $msg["error"]=1;
              $msg["status"] = "Thông tin chung đơn hàng không tồn tại";
              return response()->json($msg);
            }
            $total = $g_orders[0]->total;
            $shipping_cost = $g_orders[0]->shipping_cost;
            $shipping_cost_ex = $g_orders[0]->shipping_cost_ex;
            $delivery_date = $g_orders[0]->delivery_date;


            foreach ($m_orders as $m_order) {
              $farmer_id = $m_order["farmerID"];
              $product_id = $m_order["prodID"];
              $qty = $m_order["qty"];
              $order_type = $m_order["order_type"];
              $m_item = DB::select('SELECT `product_id`, `price`, `quantity`, `order_type`
                                      FROM `m_orders` 
                                     WHERE `product_id` = ?
                                       AND `farmer_id` = ?
                                       AND `order_id` = ?', [$product_id, $farmer_id, $order_id]);
              if(count($m_item) > 0){
                //UPDATE
                if(round($qty, 1) == 0){ 
                  $total = $total - $m_item[0]->price;
                  //$this->removeItemAdmin($order_id, $product_id, $farmer_id);
                  // DB::delete('DELETE FROM `m_orders`
                                    // WHERE `order_id` = ?
                                      // AND `product_id` = ?', [$order_id, $product_id]
                            // );
                  DB::statement('UPDATE `m_orders`
                                    SET `quantity` = 0,
                                        `price` = 0,
                                        `price_farmer` = 0
                                  WHERE `order_id` = ?
                                    AND `product_id` = ?
                                    AND `farmer_id` = ?', [$order_id, $product_id, $farmer_id]
                                );                  
                }
                else {
                  $product = DB::select('SELECT p.`category`, tr.`unit_quantity`, tr.`price`, tr.`unit`, tr.`price_farmer`, tr.`price_wholesale`
                                           FROM `products` p, `trading` tr
                                          WHERE p.`id` = tr.`product_id`
                                            AND ROUND(tr.`capacity` - tr.`sold`, 1) >= ?
                                            AND tr.`farmer_id` = ?
                                            AND tr.`product_id` = ?
                                            AND tr.`delivery_date` = ?', [(round($qty, 1) - round($m_item[0]->quantity, 1)), 
                                            $farmer_id, $product_id, $delivery_date]
                                       );
                  if(count($product) <= 0)
                  {
                      $msg["failed:".$product_id] = $m_order;
                      $msg["quantity_a"] = round($qty, 1) - round($m_item[0]->quantity, 1);
                      $msg["delivery_date"]= $delivery_date;
                      $msg["farmer_id"] = $farmer_id;
                      $msg["product_id"] = $product_id;
                      $msg["m_quantity"]= round($m_item[0]->quantity, 1);
                      $msg["qty"]= round($qty, 1);
                  }
                  else if (round($qty, 1) > 0){
                      $msg["success:".$product_id] = $m_order;
                      $msg["order_type"] = $order_type;
                      $quantity = round($qty, 2);
                      $new_quantity = $quantity - $m_item[0]->quantity;
                      $price = round((round($qty, 2) * $product[0]->price)/$product[0]->unit_quantity);
                      $price_farmer = round((round($qty, 2) * $product[0]->price_farmer)/$product[0]->unit_quantity);
                      switch($order_type) {
                        case 1://give as gift - free
                          $price = 0;
                          $msg["case1"] = 1;
                          break;
                        case 2://farmer_price order
                          $price = $price_farmer;
                          $msg["case2"] = 2;
                          break;
                        case 3: //wholesale
                          $price = round((round($qty, 2)  * $product[0]->price_wholesale)/$product[0]->unit_quantity);
                          $msg["case3"] = 3;
                          break;
                        case 4: //package
                        $price = round(0.95*$price);
                        $msg["case4"] = 4;
                        break;
                        default: //normal sale
                          $msg["case"] = 0;
                          break;
                        //do nothing as price is set above for normal sale;
                      }
                      $category = $product[0]->category;
                      $unit = $product[0]->unit;

                      $total = $total + $price - $m_item[0]->price;

                      DB::statement('UPDATE m_orders
                                        SET `quantity` = ?,
                                            `price` = ?,
                                            `price_farmer` = ?,
                                            `order_type` = ?
                                      WHERE `order_id` = ?
                                        AND `product_id` = ?
                                        AND `farmer_id` = ?', [$quantity, $price, $price_farmer, $order_type, $order_id, $product_id, $farmer_id]
                                    );

                      DB::statement('UPDATE `trading` 
                                        SET `sold` = `sold` + ? 
                                      WHERE `delivery_date` = ?
                                        AND `farmer_id` = ? 
                                        AND `product_id` = ?', [$new_quantity, $delivery_date, $farmer_id, $product_id]);

                      //Proccess the elements in case package is order
                      if($category == 0) //package
                      {
                          DB::statement('UPDATE `trading` AS tr, `m_packages` AS m 
                                            SET tr.`sold` = tr.`sold` + m.`quantity` * ?
                                          WHERE tr.`farmer_id` = m.`farmer_id`
                                            AND tr.`product_id` = m.`product_id` 
                                            AND tr.`delivery_date` = m.`delivery_date`
                                            AND tr.`delivery_date` = ?
                                            AND m.`package_id` = ?', [$new_quantity, $delivery_date, $product_id]);
                      }
                  }
                }
              }
              else {
                $product = DB::select('SELECT p.`category`, tr.`unit_quantity`, tr.`price`, tr.`unit`, tr.`price_farmer`, tr.`price_wholesale`
                                       FROM `products` p, `trading` tr
                                      WHERE p.`id` = tr.`product_id`
                                        AND ROUND(tr.`capacity` - tr.`sold`, 1) >= ?
                                        AND tr.`farmer_id` = ?
                                        AND tr.`product_id` = ?
                                        AND tr.`delivery_date` = ?', [round($qty, 2), $farmer_id, $product_id, $delivery_date]);
                if(count($product) < 1)
                {
                    $msg["failed:".$product_id] = $m_order;
                }
                else if (round($qty, 1) > 0){
                    $msg["success:".$product_id] = $m_order;

                    $quantity = round($qty, 2);
                    $price = round((round($qty, 2)  * $product[0]->price)/$product[0]->unit_quantity);
                    $price_farmer = round((round($qty, 2) * $product[0]->price_farmer)/$product[0]->unit_quantity);
                    $category = $product[0]->category;
                    $unit = $product[0]->unit;
                    switch($order_type) {
                      case 1://give as gift - free
                        $price = 0;
                        break;
                      case 2://farmer_price order
                        $price = $price_farmer;
                        break;
                      case 3: //wholesale
                        $price = round((round($qty, 2)  * $product[0]->price_wholesale)/$product[0]->unit_quantity);
                        break;
                      case 4: //package
                        $price = round(0.95*$price);
                        break;
                      default: //normal sale
                        break;
                        //do nothing as price is set above for normal sale;
                    }

                    $total = $total + $price;

                    DB::insert('INSERT INTO m_orders(`order_id`, `farmer_id`, `product_id`, `quantity`, `order_quantity`, `unit`, `price`, `price_farmer`, `order_type`) VALUES(?,?,?,?,?,?,?,?,?)', [$order_id, $farmer_id, $product_id, $quantity, $quantity, $unit, $price, $price_farmer, $order_type]);

                    DB::statement('UPDATE `trading` 
                                      SET `sold` = `sold` + ? 
                                    WHERE `delivery_date` = ?
                                      AND `farmer_id` = ? 
                                      AND `product_id` = ?', [$quantity, $delivery_date, $farmer_id, $product_id]);

                    //Proccess the elements in case package is order
                    if($category == 0) //package
                    {
                        DB::statement('UPDATE `trading` AS tr, `m_packages` AS m 
                                          SET tr.`sold` = tr.`sold` + m.`quantity` * ?
                                        WHERE tr.`farmer_id` = m.`farmer_id`
                                          AND tr.`product_id` = m.`product_id` 
                                          AND tr.`delivery_date` = m.`delivery_date`
                                          AND tr.`delivery_date` = ?
                                          AND m.`package_id` = ?', [round($qty, 2), $delivery_date, $product_id]);
                    }

                  }
                }
            }
            $msg["order_id"] = $order_id;//price subtraction
            if(($total - $shipping_cost) >= 500000 && $shipping_cost > 0){
                $total = $total - $shipping_cost;
                $shipping_cost = 0;
            }
            else if($total < 500000 && $shipping_cost == 0)
            {
              $shipping_cost = $shipping_cost_ex;
              $total = $total + $shipping_cost;
            }
            if($shipping_cost != 0 && $total == $shipping_cost) {
              $shipping_cost = 0;
              $total = 0;
            }

            //update trading table
            DB::statement('UPDATE `g_orders` 
                              SET `total` = ? ,
                                  `order_total` = ? ,
                                  `shipping_cost` = ?
                            WHERE `order_id` = ?', [$total, $total, $shipping_cost, $order_id]);
              
            $msg["error"]=0;
            $msg["status"] = "Chỉnh sửa đơn hàng thành công.";
            return response()->json($msg);
        }
        else {
           return redirect()->back();
        }
        
    }

	public function removeItemAdmin($order_id, $product_id, $farmer_id)
    {
        if(Auth::check()){

            $m_orders = DB::select('SELECT m.`id`, m.`quantity`, m.`unit`, m.`price`, g.`total`, d.`shipping_cost` "shipping_cost_ex", g.`delivery_date`, p.`category`, g.`shipping_cost` "shipping_cost"
                                     FROM `m_orders` m, `g_orders` g, `district` d, `products` p
                                    WHERE g.`order_id` = m.`order_id`
                                      AND d.`id` = g.`delivery_district`
                                      AND p.`id` = m.`product_id`
                                      AND g.`order_id` = ?
                                      AND m.`product_id` = ?
                                      AND m.`farmer_id` = ?', [$order_id, $product_id, $farmer_id]);
            if(!$m_orders)
            {
                $msg["error"] = 1;
                $msg["status"] = "Order Item not exists";
                $msg["order_id"] = $order_id;
                $msg["product_id"]= $product_id;
                $msg["farmer_id"] = $farmer_id;
                return response()->json($msg);
            }
            $quantity = $m_orders[0]->quantity;
            $price = $m_orders[0]->price;
            $total = $m_orders[0]->total;
            $shipping_cost = $m_orders[0]->shipping_cost;
            $shipping_cost_ex = $m_orders[0]->shipping_cost_ex;
            $delivery_date = $m_orders[0]->delivery_date;
            $category = $m_orders[0]->category;

            if($shipping_cost == 0 && ($total - $price) < 500000){
                $total = $total - $price + $shipping_cost_ex;
                $shipping_cost = $shipping_cost_ex;
                $msg["if"] = 1;
            }
            else {
                $total = $total - $price;
                $msg["else"] = 1;
            }
            $msg["total"]=$total;
            $msg["shipping_cost_ex"] = $shipping_cost_ex;
            $msg["shipping_cost"] = $shipping_cost;
            $msg["price"] = $price;
         	DB::delete('DELETE FROM m_orders
                              WHERE `id` = ?', [$m_orders[0]->id]
                      );

         	//update trading table
            DB::statement('UPDATE `g_orders` 
                              SET `total` = ? ,
                                  `order_total` = ? ,
                                  `shipping_cost` = ?
                            WHERE `order_id` = ?', [$total, $total, $shipping_cost, $order_id]);

            DB::statement('UPDATE `trading` 
                              SET `sold` = `sold` - ? 
                            WHERE `status` = 1 
                              AND `delivery_date` = ?
                              AND `farmer_id` = ? 
                              AND `product_id` = ?', [$quantity, $delivery_date, $farmer_id, $product_id]);

        	//Proccess the elements in case package is order
        	if($category == 0) //package
        	{
	        	DB::statement('UPDATE `trading` AS tr, `m_packages` AS m 
	        		                SET tr.`sold` = tr.`sold` - m.`quantity` * ?
								            WHERE tr.`farmer_id` = m.`farmer_id`
                              AND tr.`product_id` = m.`product_id` 
								              AND tr.`status` = 1
                              AND tr.`delivery_date` = m.`delivery_date`
                              AND tr.`delivery_date` = ?
								              AND m.`package_id` = ?', [$quantity, $delivery_date, $product_id]);

        	}
          $msg["error"]=0;
          $msg["status"] = "Bỏ sản phẩm thành công";

          return response()->json($msg);
        }
        else {
           return redirect()->back();
        }
        
    }

}
