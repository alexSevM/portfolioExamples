<?

namespace MyCompany;

use \Bitrix\Main\Loader;
use \Bitrix\Sale\Basket;

use MyCompany\HandlerHighloadblock as HlHandler,
    MyCompany\User as UserHandler,
    MyCompany\Subscriptions as Subscriptions;

class HandlerPayment
{
    /**
     * Создание рекуррентного платежа
     *
     * @param int $orderID - The ID of the order
     * @param float $summOrder - The amount of the order
     * @param int $userId - The ID of the user
     * @return void
     */
    public static function createReccurentPayment($orderID, $summOrder, $userId)
    {
        if(!$orderID || !$summOrder || !$userId)
            return;

        //Получаем тип и идентификатор сохраненной оплаты
        $arTypeAndIDSaveReccurentPayment = UserHandler::getFieldUser(
            $userId,
            [
                "UF_PAYMENT_TYPE",
                "UF_PAYMENT_ID"
            ]
        );

        //Проверяем что пользователь существует
        if (empty($arTypeAndIDSaveReccurentPayment)) {
            file_put_contents(__DIR__ . "/logs/reccurents_payment_" . date('d.m.Y') . ".txt", print_r("Ошибка, пользователь $userId не найден", true) . "\r\n", FILE_APPEND);
            return;
        }
        //Проверяем что у пользователя заполнено поле - Сохраненный идентификатор сохраненной оплаты
        if (empty($arTypeAndIDSaveReccurentPayment['UF_PAYMENT_ID'])) {
            \MyCompany\Order::addCommentToOrder($orderID, 'Ошибка. Пользователь не имеет сохранненого метода оплаты');
            file_put_contents(__DIR__ . "/logs/reccurents_payment_" . date('d.m.Y') . ".txt", print_r("Пользователь $userId не имеет сохранненого метода оплаты", true) . "\r\n", FILE_APPEND);
            return;
        }

        try {
            $client = new Client();
            // Данные магазина для авторизации
            $client->setAuth(SHOPID, SECRET_KEY);

            $payment = $client->createPayment(
                [
                    'amount' => [
                        'value' => $summOrder,
                        'currency' => 'RUB',
                    ],
                    "metadata" => [
                        "id_order" => $orderID
                    ],
                    'capture' => true,
                    'payment_method_id' => $arTypeAndIDSaveReccurentPayment['UF_PAYMENT_ID'],
                    'description' => 'Оплата подписки (заказ -  ' . $orderID . ')',
                ],
                uniqid('', true)
            );
            //Записываем отправленный запрос
            file_put_contents(__DIR__ . "/logs/reccurents_payment_" . date('d.m.Y') . ".txt", "Результат созданного реккуретного платежа - " . print_r($payment, true) . "\r\n", FILE_APPEND);
        } catch (Exception $e) {

            $arFields = [
                "UF_SUMM_ORDER" => $summOrder, // сумма заказ
                "UF_COMMENT" => $e->getMessage(), // Статус ответа
                "UF_ID_NOT_PAID_SUBSCRIPTION" => $orderID, // заказ
                "UF_ID_USER" => $userId,
            ];
            // Если по какой то причине не удалось создать реккурентный платеж, то добавляем запись в HL-BLOCK неоплаченных подписок
            (new HlHandler(Subscriptions::HL_BLOCK_SUBSCRIPTIONS_UNPAID))->add($arFields);

            file_put_contents(__DIR__ . "/logs/reccurents_payment_" . date('d.m.Y') . ".txt", 'Ошибка при проведении платежа - ' . print_r($e->getMessage(), true) . "\r\n", FILE_APPEND);
        }
    }
}
