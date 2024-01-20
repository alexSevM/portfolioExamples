<?php

namespace MyCompany;

use \Bitrix\Main\Loader;
use \Bitrix\Sale\Basket;

use MyCompany\HandlerHighloadblock as HlHandler,
    MyCompany\User as UserHandler,
    MyCompany\HandlerPayment as HlPayment;

class Subscriptions
{

    const HL_BLOCK_SUBSCRIPTIONS = "Subscriptions";
    const HL_BLOCK_SUBSCRIPTIONS_UNPAID = "UnpaidSubscriptions";
    const DAY_NEXT_PAYMEND = 25;

    public function __construct()
    {
        Loader::includeModule("highloadblock");
    }

    /**
     * Проверяяем что товар принадлежит инфоблоку с подписками
     *
     * Если товар из инфоблока подписок, возвращаем true
     *
     * @param int $idProduct
     * @return bool
     */
    public static function checkProductSubscription(int $idProduct): bool
    {
        $idBlockProduct = (int) \CIBlockElement::GetIBlockByID($idProduct);
        if ($idBlockProduct)
            if (array_search($idBlockProduct, config('iblock')) == config('iblockCode.subscription'))
                return true;

        return false;
    }

    /**
     * Получает данные о подписках из корзины заказа.
     *
     * @param \Bitrix\Sale\Basket $obBasket Объект корзины заказа
     * @return array Массив с данными о подписках
     */
    public static function getSubscriptionDataFromCart(\Bitrix\Sale\Basket $obBasket): array
    {
        $subscriptionData = [];

        foreach ($obBasket as $basketItem) {
            $productName = $basketItem->getField('NAME');
            $productQuantity = $basketItem->getQuantity();

            $subscriptionData["TYPE_SUBSCRIPTION"]['NAME'][] =  $productName;
            $subscriptionData['TYPE_SUBSCRIPTION']["COUNT"][] =  $productQuantity;
        }
        return $subscriptionData;
    }

    /**
     * Проверяет тип товаров в корзине.Checks the type of items in the cart.
     *
     * @param \Bitrix\Sale\Basket $obBasket Объект корзины.
     * @return bool Возвращает true, если все товары в корзине относятся к одному типу, в противном случае возвращает false.
     */
    public static function checkCartItemsType(\Bitrix\Sale\Basket $obBasket)
    {
        $statusNotSuscbription = false; // Статус наличия товаров без подписки
        $statusSubscription = false; // Статус наличия товаров с подпиской

        foreach ($obBasket as $basketItem) {
            $productId = $basketItem->getProductId();

            //Проверяем что товар принадлежит инфоблоку с подписками
            if (self::checkProductSubscription($productId))
                $statusSubscription = true;
            else
                $statusNotSuscbription = true;

            //Если найден товар и с подпиской и без, то выводим ошибку, т.к. два типа товара нельзя хранить в одном заказе
            if ($statusNotSuscbription && $statusSubscription)
                return true;
        }
        return false;
    }

    public static function CheckDifferentTypesProducts(Bitrix\Main\Event $event)
    {
        $obBasket = $event->getParameter("ENTITY")->getBasket();

        //Если найден товар и с подпиской и без, то выводим ошибку
        if (self::checkCartItemsType($obBasket))
            return new \Bitrix\Main\EventResult(
                \Bitrix\Main\EventResult::ERROR,
                \Bitrix\Sale\ResultError::create(
                    new \Bitrix\Main\Error(
                        "В заказе имеются товары, относящиеся к категории Подписка и товары из Каталога. Удалите один из товаров (подписка или товары из каталога). 
                        Оформление заказа, состоящего из подписок и товаров каталога невозможно!",
                        "GRAIN_IMFAST"
                    )
                )
            );
    }
    /**
     * Проверка перед оформлением записи подписки, что все товары принадлежат к категории подписка
     *
     * @param \Bitrix\Main\Event $event
     * @return void
     * @throws \Bitrix\Main\ArgumentNullException
     */
    public static function processSubscriptionOrder(\Bitrix\Main\Event $event)
    {
        $bNewOrder = $event->getParameter("IS_NEW"); // статус заказа, является ли он новым
        $obOrder = $event->getParameter("ENTITY"); // объект заказа
        $orderID = $obOrder->getId(); // id заказа
        $obBasket = $obOrder->getBasket(); // корзина заказа

        if ($propSubscriptionOrder = $obOrder->getPropertyCollection()->getItemByOrderPropertyCode('SUBSCRIPTION'))
            $propSubscriptionOrderValue = $propSubscriptionOrder->getValue(); // Получаем значение свойства Подписка на кофе

        /**
         * Если заказ новый, проверяем его условие, что заказ является подпиской и устанавливаем у него свойство - Подписка
         * Необходимо для будущих проверок при оплате заказа
         */
        if ($bNewOrder) {
            // Проверяем что все товары с со свойством Подписка, иначе ничего не делаем
            if (self::checkCartItemsType($obBasket))
                return;

            // устанавливаем свойство заказа - Подписка дял будущей проверки
            \MyCompany\Order::updateOrderProperties($obOrder, ["SUBSCRIPTION" => "Y"]);
        }


        //Если заказ не оплачен = ничего не делаем
        if (!$obOrder->getField('PAYED') || !(($obOrder->getField('PAYED') == 'Y')))
            return;

        // Если заказ оплачен, проверяем что он является подпиской
        if ($propSubscriptionOrderValue == 'Y') {
            //Если заказ пересохранен и такая запись уже имеется в таблице, ничего не делаем
            if (!empty(self::checkIfRecordExistsAndDoNothingIfSo($orderID)))
                return;

            // Запись в HlBlock подписок
            self::addSubscriptionToHighloadBlock(self::getSubscriptionDataFromCart($obBasket),  $obOrder);
        }
    }

    /**
     * Запись в HL block подписок
     *
     * @param $arFields
     * @return bool
     */
    public static function addSubscriptionToHighloadBlock($subscriptionData, \Bitrix\Sale\Order $obOrder): void
    {
        try {
            // Получаем поля заказа
            $priceOrder = $obOrder->getPrice(); // сумма заказа
            $priceDelivery = $obOrder->getDeliveryPrice(); // сумма доставки

            $arFields = [
                "UF_USER" => $obOrder->getUserId(), // ID пользователя
                "UF_NUMBER_ORDER" => $obOrder->getId(), // ID заказа
                "UF_TYPE_SUBCRIPTION" =>  $subscriptionData["TYPE_SUBSCRIPTION"]['NAME'], // Тип подписки
                "UF_COUNT_SUBSCRIPTION" =>  $subscriptionData['TYPE_SUBSCRIPTION']["COUNT"], // Количество подписок
                "UF_NEXT_PAYMENT_DATE" => date(self::DAY_NEXT_PAYMEND . '.m.Y '),    // День повторного списания  
                "UF_PRICE_SUBCRIPTION" => $priceOrder - $priceDelivery, // Сумма подписки
                "UF_PRICE_DELIVERY" => $priceDelivery, // Сумма доставки
                "UF_ACTIVE_SUBSCRIPTION" => true, // Активна ли подписка

            ];
            // Добавляем подписку в HL-BLOCK
            $result = (new HlHandler(self::HL_BLOCK_SUBSCRIPTIONS))->add($arFields);

            if (!$result->isSuccess())
                AddMessage2Log('Ошибка при добавлении подписки в HL-BLOCK ' . print_r($result->getErrorMessages(), true));
        } catch (\Exception $e) {
            //Записываем ошибку, если возникла
            AddMessage2Log('Ошибка при добавлении подписки в HL-BLOCK ' . print_r($e->getMessage(), true));
        }
    }

    /**
     * Получаем запись подписки из HL блока, третий параметр определяет какую подписку ищем - любую или активную
     *
     * @param $orderId
     * @return array
     */
    public static function checkIfRecordExistsAndDoNothingIfSo($orderId, $statusSubscrirtion = false): array
    {

        if ($statusSubscrirtion) {
            /**
             * Ищем активную подписку
             */
            $filter = [
                "UF_NUMBER_ORDER" => $orderId,
                "UF_ACTIVE_SUBSCRIPTION" => true
            ];
        } else {
            /**
             * Ищем любую подписку, чтобы не вносить сохранения в HL блок с подписками
             */
            $filter = [
                "UF_NUMBER_ORDER" => $orderId,
            ];
        }

        $result = (new HlHandler(self::HL_BLOCK_SUBSCRIPTIONS))->getList(
            [
                "select" => [
                    "ID"
                ],
                "filter" =>
                $filter
            ]
        );

        return
            !empty($result) ? $result : [];
    }

    /**
     * Отмена определенной подписки пользователя
     *
     * @param $orderId
     * @param $canselStatus
     * @param $description
     * @return array|false|float|string|string[]|void
     */
    public static function canselSubscription(int $orderId, bool $canselStatus = true, string $description = '', string $user = 'Администратор'): string
    {
        if ($canselStatus) {
            $result = self::checkIfRecordExistsAndDoNothingIfSo($orderId);

            if (empty($result))
                return json_encode("Подписки с заказом $orderId не существует");

            if (!empty($result))
                $resultUpdateSubcription = (new HlHandler(self::HL_BLOCK_SUBSCRIPTIONS))->update($result[0]['ID'], ["UF_ACTIVE_SUBSCRIPTION" => false, "UF_DATE_CANSEL" => date("d.m.Y H:i:s")]);

            if (!$resultUpdateSubcription->isSuccess()) {
                file_put_contents("log_error_subscription.txt", print_r($resultUpdateSubcription->getErrorMessages(), true), FILE_APPEND);
                return json_encode("К сожалению произошла ошибка - " . $resultUpdateSubcription->getErrorMessages());
            }

            return json_encode("Подписка успешно снята");
        }
    }

    /**
     * Отмена всех подписок текущего пользователя
     *
     * Убирает сохраненный способ оплаты
     *
     * @return string
     */
    public function canselSubscriptionsALL($nameHlBlock, string $typeUser = 'Администратор'): string
    {
        global $USER;

        $arSubscriptions = (new HlHandler(self::HL_BLOCK_SUBSCRIPTIONS))->getList(
            [
                "select" => [
                    "ID"
                ],
                "filter" => [
                    "UF_USER" => $USER->GetID(),
                    "UF_ACTIVE_SUBSCRIPTION" => true
                ]
            ]
        );

        /*
         * Очистка сохраннеого метода оплаты и id метода оплаты
         */
        $fields = [
            "UF_PAYMENT_TYPE" => "",
            "UF_PAYMENT_ID" => "",
        ];

        UserHandler::update($USER->GetID(), $fields);

        /**
         * конец очистки пользовательского свойства
         */
        // Список отмененных подписок
        $arSubscription = [];
        if (!empty($arSubscriptions)) {
            foreach ($arSubscriptions as $subscription) {
                // Получаем полный список отмененных подписок
                $arSubscription[] = $subscription['ID'];

                $result = (new HlHandler(self::HL_BLOCK_SUBSCRIPTIONS))->update($subscription['ID'], ["UF_ACTIVE_SUBSCRIPTION" => false, "UF_DATE_CANSEL" => date("d.m.Y")]);
                if (!$result->isSuccess()) {

                    return json_encode("К сожалению произошла ошибка - " . $result->getErrorMessages());
                }
            }

            return json_encode("Подписки успешно сняты!");
        }
    }


    /**
     * Получаем список активных подписок на текущий месяц
     */
    public static function getActiveSubscriptons(): array
    {
        $lastDayMonth = date('t'); // последний день текущего месяца
        $arSubscriptionsExtend = (new HlHandler(self::HL_BLOCK_SUBSCRIPTIONS))->getList(
            [
                "filter" => [
                    ">=UF_NEXT_PAYMENT_DATE" => date("01.m.Y"),
                    "<=UF_NEXT_PAYMENT_DATE" => date($lastDayMonth . ".m.Y"),
                    "UF_ACTIVE_SUBSCRIPTION" => true
                ]
            ]
        );

        return ($arSubscriptionsExtend) ?? [];
    }

    /**
     * Продление всех активных подписок и их продление
     *
     * @param $paymentSavedReccurentID // ID сохранненого метода оплаты рекуреннтного платежа
     * @return bool
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ArgumentNullException
     * @throws \Bitrix\Main\ArgumentOutOfRangeException
     * @throws \Bitrix\Main\ArgumentTypeException
     * @throws \Bitrix\Main\NotImplementedException
     * @throws \Bitrix\Main\NotSupportedException
     * @throws \Bitrix\Main\ObjectException
     * @throws \Bitrix\Main\ObjectNotFoundException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    public static function createSubscription($nameHlBlock): void
    {
        $arSubscriptionsExtend = self::getActiveSubscriptons(); // список активных подписок

        if (!empty($arSubscriptionsExtend)) {
            foreach ($arSubscriptionsExtend as $subscription) {
                /*
                 * Деактивируем старые подписки
                 */
                $result = (new HlHandler(self::HL_BLOCK_SUBSCRIPTIONS))->update($subscription['ID'], ["UF_ACTIVE_SUBSCRIPTION" => false, "UF_DATE_CANSEL" => date("d.m.Y")]);
                if (!$result->isSuccess())
                    file_put_contents(__DIR__ . "/logs/log_error_subscription" . date('d.m.Y') . ".txt", "Ошибка при деактивации старой подписки - " . print_r($result->getErrorMessages() . "\r\n", true), FILE_APPEND);

                try {
                    // Получаем объект старого заказа
                    $obOrder = \Bitrix\Sale\Order::load($subscription['UF_NUMBER_ORDER']);
                    if (is_object($obOrder)) {
                        // методы оплаты
                        $paymentCollection = $obOrder->getPaymentCollection();
                        foreach ($paymentCollection as $payment) {
                            $paySysID = $payment->getPaymentSystemId(); // ID метода оплаты
                            $paySysName = $payment->getPaymentSystemName(); // Название метода оплаты
                        }

                        // службы доставки
                        $shipmentCollection = $obOrder->getShipmentCollection();
                        foreach ($shipmentCollection as $shipment) {
                            if ($shipment->isSystem()) continue;
                            $shipID = $shipment->getField('DELIVERY_ID'); // ID службы доставки
                            $shipName = $shipment->getField('DELIVERY_NAME'); // Название службы доставки
                        }

                        // объект нового заказа
                        $orderNew = \Bitrix\Sale\Order::create(ID_SITE_SBMR, $subscription['UF_USER']);

                        // код валюты
                        $orderNew->setField('CURRENCY', $obOrder->getCurrency());

                        // задаём тип плательщика
                        $orderNew->setPersonTypeId($obOrder->getPersonTypeId());

                        // создание корзины
                        $basketNew = Basket::create(ID_SITE_SBMR);

                        // дублируем корзину заказа
                        $basket = $obOrder->getBasket();

                        // привязываем корзину к заказу
                        $orderNew->setBasket($basketNew);

                        // задаём службу доставки
                        $shipmentCollectionNew = $orderNew->getShipmentCollection();
                        $shipmentNew = $shipmentCollectionNew->createItem();
                        foreach ($basket as $basketItem) {
                            $item = $basketNew->createItem('catalog', $basketItem->getProductId());
                            $item->setFields(
                                [
                                    'QUANTITY' => $basketItem->getQuantity(),
                                    'CURRENCY' => $obOrder->getCurrency(),
                                    'LID' => ID_SITE_SBMR,
                                    'PRODUCT_PROVIDER_CLASS' => '\CCatalogProductProvider',
                                ]
                            );
                            $newShipmentItem = $shipmentNew->getShipmentItemCollection()->createItem($item);
                        }
                        $shipmentNew->setFields(
                            [
                                'DELIVERY_ID' => $shipID,
                                'DELIVERY_NAME' => $shipName,
                                'CURRENCY' => $obOrder->getCurrency(),
                                'PRICE_DELIVERY' => $obOrder->getDeliveryPrice(),
                                'CUSTOM_PRICE_DELIVERY' => 'Y',

                            ]
                        );

                        // пересчёт стоимости доставки
                        $shipmentCollectionNew->calculateDelivery();

                        // задаём метод оплаты
                        $paymentCollectionNew = $orderNew->getPaymentCollection();
                        $paymentNew = $paymentCollectionNew->createItem();
                        $paymentNew->setFields(
                            [
                                'PAY_SYSTEM_ID' => $paySysID,
                                'PAY_SYSTEM_NAME' => $paySysName,
                                'SUM' => ($obOrder->getPrice()),
                            ]
                        );

                        // задаём свойства заказа
                        $propertyCollection = $obOrder->getPropertyCollection();
                        $propertyCollectionNew = $orderNew->getPropertyCollection();

                        /**
                         * Получаем ФИО пользователя из заказа
                         */
                        $strFullName = $propertyCollection->getPayerName()->getValue() ?? '';

                        /**
                         * Получаем ФИО пользователя ил личных данных, если в заказе его нет
                         */
                        if (empty($strFullName))
                            $strFullName = CertificateMoney::getFullNameUser($subscription['UF_NUMBER_ORDER'], $subscription['UF_USER']);

                        foreach ($propertyCollection as $property) {
                            if (!empty($property->getPropertyId())) {

                                // получаем свойство в коллекции нового заказа
                                $somePropValue = $propertyCollectionNew->getItemByOrderPropertyId($property->getPropertyId());

                                // задаём значение свойства
                                if (!empty($somePropValue))
                                    $somePropValue->setValue($property->getField('VALUE'));
                            }
                        }
                        /**
                         * Задаем ФИО в новый заказ
                         */
                        $propertyCollectionNew->getPayerName()->setValue($strFullName);
                        // сохраняем новый заказ
                        $orderNew->doFinalAction(true);
                        $rs = $orderNew->save();

                        if (!$rs->isSuccess())
                            file_put_contents(__DIR__ . "/logs/log_error_subscription" . date('d.m.Y') . ".txt", "Ошибка при сохранении нового заказа подписки - " . print_r($rs->getErrorMessages() . "\r\n", true), "createSubscription");
                        else {
                            HlPayment::createReccurentPayment($orderNew->getId(), $orderNew->getPrice(), $subscription['UF_USER']);
                        }
                    }
                } catch (Exception $e) {
                    file_put_contents(__DIR__ . "/logs/log_error_subscription" . date('d.m.Y') . ".txt", "Ошибка при сохранении нового заказа подписки - " . print_r($e->getMessage() . "\r\n", true), "createSubscription");
                }
            }
        }
    }


    /**
     * Проверка повторных списаний на оплаченность, в случае если до повторного списания клиент сам оплатил подписку
     * Если заказ оплачен клиентом - удаляем из списка повторных списаний
     */
    public static function CheckingaAndDeletePaymentRepeatSubscriptions(): void
    {
        $arSubscriptionsExtend =  (new HlHandler(self::HL_BLOCK_SUBSCRIPTIONS_UNPAID))->getList([]);
        if (!empty($arSubscriptionsExtend)) {
            foreach ($arSubscriptionsExtend as $subscription) {
                $obOrder = \Bitrix\Sale\Order::load($subscription['UF_ID_NOT_PAID_SUBSCRIPTION']);

                if ($obOrder->isPaid()) (new HlHandler(self::HL_BLOCK_SUBSCRIPTIONS_UNPAID))->delete($subscription['ID']);
            }
        }
    }

    /**
     * Повторная оплата подписок, которые не оплатились с первой попытки
     */
    public static function repeatPaymentSubscription()
    {
        //Удаляем из повторных оплат оплаченные заказы
        self::CheckingaAndDeletePaymentRepeatSubscriptions();
        // Получаем список подписок, которые необходимо повторно оплатить
        $arSubscriptionsExtend =  (new HlHandler(self::HL_BLOCK_SUBSCRIPTIONS_UNPAID))->getList([]);

        /**
         * Если список не пустой
         * Создаем повторно реккурентый платеж
         */
        if (!empty($arSubscriptionsExtend)) {
            foreach ($arSubscriptionsExtend as $subscription)
                HlPayment::createReccurentPayment($subscription['UF_ID_NOT_PAID_SUBSCRIPTION'], $subscription['UF_SUMM_ORDER'], $subscription['UF_ID_USER']);
        }
    }
}
