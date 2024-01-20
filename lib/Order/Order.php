<?

namespace MyCompany;

class Order
{
    /**
     * Создает новый заказ.
     *
     * @param array $fields Поля заказа.
     * @return int|false Возвращает ID созданного заказа или false в случае ошибки.
     */
    public static function createOrder(array $fields)
    {
        $order = new \Bitrix\Sale\Order();
        $order->setFields($fields);

        if ($order->save()) {
            return $order->getId();
        }

        return false;
    }

    /**
     * Получает информацию о заказе по его ID.
     *
     * @param int|\Bitrix\Sale\Order $order ID заказа или объект заказа.
     * @return array|false Возвращает массив с информацией о заказе или false, если заказ не найден.
     */
    public static function getOrderInfo($order)
    {
        if ($order instanceof \Bitrix\Sale\Order) {
            $obOrder = $order;
        } else {
            $obOrder = \Bitrix\Sale\Order::load($order);
        }

        if ($obOrder) {
            return $obOrder->getFieldValues();
        }

        return false;
    }

    /**
     * Обновляет информацию о заказе.
     *
     * @param int|\Bitrix\Sale\Order $order ID заказа или объект заказа.
     * @param array $fields Поля заказа для обновления.
     * @return bool Возвращает true, если обновление успешно, иначе false.
     */
    public static function updateOrder($order, array $fields)
    {
        if ($order instanceof \Bitrix\Sale\Order) {
            $obOrder = $order;
        } else {
            $obOrder = \Bitrix\Sale\Order::load($order);
        }

        if ($obOrder) {
            $obOrder->setFields($fields);
            $result = $obOrder->save();

            return $result->isSuccess(); // Проверяем успешность сохранения заказа
        }

        return false;
    }

    /**
     * Удаляет заказ по его ID.
     *
     * @param int|\Bitrix\Sale\Order $order ID заказа или объект заказа.
     * @return bool Возвращает true, если удаление успешно, иначе false.
     */
    public static function deleteOrder($order)
    {
        if ($order instanceof \Bitrix\Sale\Order) {
            $obOrder = $order;
        } else {
            $obOrder = \Bitrix\Sale\Order::load($order);
        }

        if ($obOrder) {
            return $obOrder->delete();
        }

        return false;
    }

    /**
     * Обновляет свойства заказа.
     *
     * @param int|\Bitrix\Sale\Order $order ID заказа или объект заказа.
     * @param array $properties Свойства заказа для обновления.
     * @return bool Возвращает true, если обновление успешно, иначе false.
     */
    public static function updateOrderProperties($order, array $properties)
    {
        if ($order instanceof \Bitrix\Sale\Order) {
            $obOrder = $order;
        } else {
            $obOrder = \Bitrix\Sale\Order::load($order);
        }

        if ($obOrder) {
            $propertyCollection = $obOrder->getPropertyCollection();

            foreach ($properties as $code => $value) {
                $propertyItem = $propertyCollection->getItemByOrderPropertyCode($code);

                if ($propertyItem) {
                    $propertyItem->setValue($value);
                }
            }

            $result = $order->save();

            return $result->isSuccess(); // Проверяем успешность сохранения заказа
        }

        return false;
    }

       /**
     * ДОбавляем комментарий к заказу
    * @param int|\Bitrix\Sale\Order $order ID заказа или объект заказа.
     * @param $message
     * @return void
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ArgumentNullException
     * @throws \Bitrix\Main\ArgumentOutOfRangeException
     * @throws \Bitrix\Main\NotImplementedException
     */
    public function addCommentToOrder($order, $message = '') : void
    {
        if ($order instanceof \Bitrix\Sale\Order) {
            $obOrder = $order;
        } else {
            $obOrder = \Bitrix\Sale\Order::load($order);
        }

        $oldCommentOrder = $obOrder->getField('USER_DESCRIPTION');
        $newCommentOrder =$oldCommentOrder ."\n".$message;
        $obOrder->setField('USER_DESCRIPTION', $newCommentOrder);
        $obOrder->save();
    }
}
