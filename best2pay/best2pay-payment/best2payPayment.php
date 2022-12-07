<?php

function checkPaymentStatus($best2payOrder, $best2payOperation, $orderId, $paymentIDBest2Pay){
    $b2p_order_id = intval($best2payOrder);
    if (!$b2p_order_id)
        return false;
    $b2p_operation_id = intval($best2payOperation);
    if (!$b2p_operation_id)
        return false;
    $order_id = intval($orderId);
    if (!$order_id)
        return false;
    $result_payment = array();
    $dbRes = DB::query('
            SELECT *
            FROM `'.PREFIX.'payment`
            WHERE `id` = \''.$paymentIDBest2Pay.'\'');
    $result_payment = DB::fetchArray($dbRes);
    $paymentParamDecoded = json_decode($result_payment[3]);
    foreach ($paymentParamDecoded as $key => $value) {
        if ($key == 'Тестовый режим') {
            $test = CRYPT::mgDecrypt($value);
        } elseif ($key == "Сектор") {
            $sector = CRYPT::mgDecrypt($value);
        } elseif ($key == "Пароль") {
            $password = CRYPT::mgDecrypt($value);
        } elseif ($key == "Передавать данные на свою ККТ") {
            $kkt= CRYPT::mgDecrypt($value);
        } elseif ($key == "Код ставки НДС для ККТ") {
            $nds= CRYPT::mgDecrypt($value);
        }
    }

    // check payment operation state
    $signature = base64_encode(md5($sector . $b2p_order_id . $b2p_operation_id . $password));
    if ($test === 'true'){
        $best2pay_url = "https://test.best2pay.net";
    } else {
        $best2pay_url = "https://pay.best2pay.net";
    }

    $repeat = 3;
    while ($repeat) {
        $repeat--;
        // pause because of possible background processing in the Best2Pay
        sleep(2);
        if( $curl = curl_init() ) {
            curl_init();
            curl_setopt($curl, CURLOPT_URL, $best2pay_url . '/webapi/Operation');
            curl_setopt($curl, CURLOPT_RETURNTRANSFER,true);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, 'sector='.$sector.'&signature='.$signature.'&id='.$b2p_order_id.'&operation='.$b2p_operation_id);
            $xml = curl_exec($curl);
            curl_close($curl);
        }
        if (!$xml)
            break;
        $xml = simplexml_load_string($xml);
        if (!$xml)
            break;
        $response = json_decode(json_encode($xml));
        if (!$response)
            break;

        if (!orderWasPayed($response, $password))
            continue;

        return true;
    }
    return false;
}

function orderWasPayed($response, $password) {
    // looking for an order
    $order_id = intval($response->reference);
    if ($order_id == 0)
        return false;
    // check payment state
    if (($response['type'] == 'PURCHASE_BY_QR' || $response['type'] == 'PURCHASE' || $response['type'] == 'AUTHORIZE') && $response['state'] == 'APPROVED')
        return false;
    // check server signature
    $tmp_response = json_decode(json_encode($response), true);
    unset($tmp_response["signature"]);
    unset($tmp_response["protocol_message"]);
    $signature = base64_encode(md5(implode('', $tmp_response) . $password));
    return $signature === $response->signature;
}
