<?

namespace MyCompany;

use \Bitrix\Main\Loader,
    \Bitrix\Sale;

class Bonuses
{
    private $userId = NULL;
    private $arBasketProduct = [];
    private $obOrder;
    private $arPropsCollectionOrder;
    private $arListTransact;

    private $arListCodeBonusesOrder = [
        'BONUSES_ACCRUED' => 'BONUSES_ACCRUED', // Бонусов уже начислено
        'MAXIMUM_BONUSES' => 'MAXIMUM_BONUSES' // Бонусов максимальное количество, которое может быть начислено

    ];

    /**
     * Статус заказа - Выполнен
     */
    const STATUS_ORDER_COMPLETED = 'F';

    function __construct()
    {
        global $USER;

        if (is_object($USER))
            $this->userId = $USER->GetId();
        else
            throw new \Exception("Empty user object");

        if (!Loader::includeModule("sale"))
            throw new \Exception("Sale Module Not Included");
    }

    /**
     * Проверка был ли применен купон
     */
    public static function checkUseCoupon($order) : bool
    {
        $coupons =  $order->getDiscount()->getApplyResult()['COUPON_LIST'];	//получаем список купонов

        return !empty($coupons);
    }

    /**
     * Получаем максимальное количество бонусов для заказа
     */
    public static function getMaxAmountBonusesByOrder($basket)
    {
        $maxCountBonuseByOrder = 0;

        foreach($basket as $basketItem)
        {
            $quantityProductInorder = (int)$basketItem->getQuantity(); // Количество товара в заказе
            $priceProductOrder = $basketItem->getPrice(); // цена товара в заказе
            $priceProductBase = $basketItem->getBasePrice(); // цена товара в каталоге товаров

            /**
             * Если цены  равны, значит скидки на товар в заказе не были применены
             */
            if($priceProductOrder == $priceProductBase)
            {
                $obElement = \CIBlockElement::GetByID($basketItem["PRODUCT_ID"])->GetNextElement();
                if (is_object($obElement))
                {
                    $arItemProperties = $obElement->GetProperties();

                    $bonusValue = (int) $arItemProperties[CUSTOM_PRICES["BONUS_COINS_PROPERTY_CODE"]]["VALUE"];


                    if ($bonusValue > 0 && $quantityProductInorder > 0)
                        $maxCountBonuseByOrder += ($bonusValue * $quantityProductInorder);
                }
            }
        }
        
        return $maxCountBonuseByOrder;
    }

    /**
     * Получение объекта заказа через сущность или через ID заказа
     */
    public static function getOrderOb(Bitrix\Main\Event $event = null, $idOrder = '')
    {
        return !empty($event) ? $event->getParameter("ENTITY") : Sale\Order::load($idOrder);
    }

    
    /**
     * Получаем свойства заказа
     */
    public static function getPropertyCollection(Sale\Order $order)
    {
        return $order->getPropertyCollection();
    }


    /**
     * Рассчет бонусов в заказ
     */
    public function CalculateBonuses(Bitrix\Main\Event $event)
    {
        $this->obOrder = $this->getOrderOb($event);
        /**
         * Если использовали купоны - ничего не делаем
         */
        if($this->checkUseCoupon($this->obOrder))
            return;

        /**
         * Если максимальное количество бонусов для заказа равно 0
         * Ничего не делаем
         */
        if(!($maxAmountBonusesByOrder = $this->getMaxAmountBonusesByOrder($this->obOrder->getBasket())))
            return;
        
    }

    /**
     * Устанавливаем свойство заказа в определенное значение
     * 
     * Третий параметр проверяет, если заказ обновляется после его первого создания
     * СОбытие OnSaleComponentOrderCreated
     */
    public function setOrderProp(string $strPropsCode, float $fPropsValue, bool $statusNeedSaveOrder = false)
    {
        $propertyOrder= $this->getPropertyCollection($this->obOrder) -> getItemByOrderPropertyCode($strPropsCode);

        if($propertyOrder)
            $propertyOrder->setValue($fPropsValue);
        
        /**
         * Проверка что заказ надо сохранить
         */
        if($statusNeedSaveOrder)
            $this->obOrder->save();
    }

    /**
     * Получаем значение свойства заказа по коду
     */
    public function getOrderprop(string $strPropsCode)
    {
        $propertyOrder= $this->getPropertyCollection($this->obOrder) -> getItemByOrderPropertyCode($strPropsCode);

        return $propertyOrder ?  $propertyOrder->getValue() : '';
    }
    
    /**
     *  Получаем общую сумму начислений бонусов по заказу
     */
    public function getAmounTransactUserByOrder($userID, $orderID)
    {
        $amountBonusesOrder = 0;

        $arFilter = [
            "USER_ID" => $userID, 
            "ORDER_ID" => $orderID
        ];

        $res = CSaleUserTransact::GetList(["ID" => "DESC"], $arFilter);

        while ($arFieldsTransact = $res->Fetch())
            $amountBonusesOrder += $arFieldsTransact['AMOUNT'];

        return $amountBonusesOrder;
    }

        /**
     *  Получаем общую сумму бонусов
     */
    public function getAmounTransactUser($userID)
    {
        $amountBonusesOrder = 0;

        $dbAccountCurrency = \CSaleUserAccount::GetList(
            [],
            ["USER_ID" => $userID ],
            false,
            false,
           ["CURRENT_BUDGET", "CURRENCY"]
        );

        while ($arAccountCurrency = $dbAccountCurrency->Fetch())
            $amountBonusesOrder += $arAccountCurrency['CURRENT_BUDGET'];

        return $amountBonusesOrder;
    }
    /**
     * Событие проверки перед созданием заказа
     * 
     * Получаем и устанавливаем максимальное количество бонусов для заказа
     */
    public function setOrderPropsMaxBonusesCreateOrder(Sale\Order $order)
    {
        $this->obOrder = $order;
        /**
         * Если использовали купоны - ничего не делаем
        */
        if($this->checkUseCoupon($this->obOrder))
            return;
        /**
         * Если максимальное количество бонусов для заказа равно 0
         * Ничего не делаем
         */
        if(!($maxAmountBonusesByOrder = $this->getMaxAmountBonusesByOrder($this->obOrder->getBasket())))
            return;
        
        /**
         * Устанавливаем максимальноманое количество бонусов
         */
        $this->setOrderProp($this->arListCodeBonusesOrder['MAXIMUM_BONUSES'], $maxAmountBonusesByOrder, true);
    }

    /**
     * Событие перед изменением заказа
     */
    public function setOrderPropsBonusesBeforeSaveOrder(Bitrix\Main\Event $event)
    {
        $this->obOrder = $this->getOrderOb($event);

        $userId = $this->obOrder->getUserId();
        $orderId = $this->obOrder->getID();

        if(!$userId || !$orderId)
            return;

        /**
         * Получаем максимальное количество бонусов у заказа и уже примененное
         */
        $BONUSES_ACCRUED = $this->getOrderprop($this->arListCodeBonusesOrder['BONUSES_ACCRUED']);
        $MAXIMUM_BONUSES = $this->getOrderprop($this->arListCodeBonusesOrder['MAXIMUM_BONUSES']);

        /**
         * Получаем начисленные бонусы по заказу и общее количество бонусов на текущий момент у пользователя
         */
        $amountBonusesByOrder = $this->getAmounTransactUserByOrder($userId, $orderId);
        $amountBonusesUser = $this->getAmounTransactUser($userId);
        
        /**
         * Если были использовали купоны при изменении заказа, устанавливаем максимальное количество бонусов - 0 и снимаем все транзакции
         */
        
        if($this->checkUseCoupon($this->obOrder))
        {
            /**
             * Снимаем применненые ранее бонусы по заказу (если количество бонусов на счете меньше снимаемного количества, снимаем только возможное количество, чтобы бонусы не ушли в минус)
             */
            if(!empty($amountBonusesByOrder))
                $this->editBonusesPersonalAccount($orderId, $userId, $amountBonusesByOrder > $amountBonusesUser ? $amountBonusesUser : $amountBonusesByOrder, '-');
    
            /**
             * Снимаем установленное количество бонусов по заказу в свойстве заказа
             */
            $this->setOrderProp($this->arListCodeBonusesOrder['BONUSES_ACCRUED'], 0);    
            return;
        }

        /**
         * Если были применены скидки к заказу (без применения купона) и максимальное количество равно нулю, снимаем все тразанкции и обнуляем свойства
         * 
         * Если купоны не применены и скидок и максимальное количество не равно нулю, обновляем максимальное количество бонусов для заказа
         */
        if(!($maxAmountBonusesByOrder = $this->getMaxAmountBonusesByOrder($this->obOrder->getBasket())))
        {
            $this->setOrderProp($this->arListCodeBonusesOrder['BONUSES_ACCRUED'], 0);    
            $this->setOrderProp($this->arListCodeBonusesOrder['MAXIMUM_BONUSES'], 0);

            if(!empty($amountBonusesByOrder))
                $this->editBonusesPersonalAccount($orderId, $userId, $amountBonusesByOrder > $amountBonusesUser ? $amountBonusesUser : $amountBonusesByOrder, '-');
            
            return;
        }
        else
            if($MAXIMUM_BONUSES !== $maxAmountBonusesByOrder)
                $this->setOrderProp($this->arListCodeBonusesOrder['MAXIMUM_BONUSES'], $maxAmountBonusesByOrder, true);

      
 
        /**
         * Если статус заказа выполнен и заказ оплачен
         */
        if($this->obOrder->getField('STATUS_ID') == self::STATUS_ORDER_COMPLETED && $this->obOrder->isPaid())
        {
            /**
             * Если максимальное количество меньше больше чем начисленных, добавляем в транзакцию нехватающие бонусы
             * 
             * И записываем в свойство начисленные бонусы нехватающие бонусы
             * 
             * Дополнительно проверяем что свойство максимальное количество бонусо меньше реально начисленных из транзакций по заказу
             * 
             * Если бонусы уже были начислены (старые заказы), но записи нет, то записываем в общее количество уже по заказу
             */
           
            if ($MAXIMUM_BONUSES > $BONUSES_ACCRUED && $MAXIMUM_BONUSES > $amountBonusesByOrder && ($differenceBonuses = $MAXIMUM_BONUSES - $amountBonusesByOrder) > 0) {
                $this->editBonusesPersonalAccount($orderId, $userId, $differenceBonuses, '+');
                $this->setOrderProp($this->arListCodeBonusesOrder['BONUSES_ACCRUED'], $differenceBonuses + $BONUSES_ACCRUED);
            }
            else if($MAXIMUM_BONUSES === $amountBonusesByOrder && $BONUSES_ACCRUED == 0)
                $this->setOrderProp($this->arListCodeBonusesOrder['BONUSES_ACCRUED'], $amountBonusesByOrder);
        }
        else
        {   
            if($amountBonusesByOrder > 0)
                $this->editBonusesPersonalAccount($orderId, $userId,  $amountBonusesByOrder > $amountBonusesUser ? $amountBonusesUser : $amountBonusesByOrder, '-');

            if($BONUSES_ACCRUED > 0)
                $this->setOrderProp($this->arListCodeBonusesOrder['BONUSES_ACCRUED'], 0);    
        }
    }

    /**
     * Функция изменения личного счета бонусов у клиента
     */
    public function editBonusesPersonalAccount($orderID, $userId, $fBonusSumm, $action = '+')
    {
        $messageTransact = str_replace(
            [
                "#AMOUNT#",
                "#ORDER_ID#"
            ],
            [
                $fBonusSumm,
                $orderID
            ], 
            $action == '+' ? CUSTOM_PRICES["BONUS_COINS_ADD_TEXT"] : CUSTOM_PRICES["BONUS_COINS_REMOVE_TEXT"]);

        \CSaleUserAccount::UpdateAccount(
            $userId,
            ($action == "+" ? +($fBonusSumm) : -($fBonusSumm)),
            "RUB",
           $messageTransact,
            $orderID
        );
    }

    public static function getAmountByOrderId(int $iOrderId)
    {
        if ($iOrderId <= 0)
            throw new \Exception("Empty order id");

        $bonusSumm = 0;

        $obBasket = \Bitrix\Sale\Basket::getList([
            "filter" => [
                "ORDER_ID" => $iOrderId
            ]
        ]);

        while($arBasketItem = $obBasket->Fetch())
        {
            $productId = (int) $arBasketItem["PRODUCT_ID"];

            if ($productId > 0)
            {
                $obElement = \CIBlockElement::GetByID($arBasketItem["PRODUCT_ID"])->GetNextElement();

                if (is_object($obElement))
                {
                    $arItemProperties = $obElement->GetProperties();

                    $bonusValue = (int) $arItemProperties[CUSTOM_PRICES["BONUS_COINS_PROPERTY_CODE"]]["VALUE"];
                    $itemsQuantity = (int) $arBasketItem["QUANTITY"];

                    if ($bonusValue > 0 && $itemsQuantity > 0)
                        $bonusSumm += ($bonusValue * $itemsQuantity);
                }
            }
        }

        return (int) $bonusSumm;
    }
}
