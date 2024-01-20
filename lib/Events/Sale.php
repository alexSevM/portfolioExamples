<?php
namespace MyCompany\Events;
use Bitrix\Main\Event,
	Bitrix\Sale\Payment,
	Bitrix\Sale\Order,
	Bitrix\Sale\Location\LocationTable,
	Bitrix\Sale\Property,
	Bitrix\Sale\Registry,
	Bitrix\Sale\PropertyValue;

/**
 * Класс для работы с событиями модуля Sale
 */
class Sale
{
    /**
     * Свойство для списка купонов в заказе
     */
    const PROPS_COUPONS_ORDER = 'LIST_COUPON_IN_ORDER';
    /**
     * Функция получает список купонов заказа и вносит их в отдельное свойство
     */
    public static function getListCouponOrderAndSetPropsCoupon(Event &$event)
    {
        $listCoupon = [];
        /** @var Order $order */

		$order = &$event->getParameter("ENTITY");
        $propertyCollection = $order->getPropertyCollection();

        $discountData = $order->getDiscount()->getApplyResult();

        /**
         * Получаем список купонов из заказа
         */
        foreach($discountData['COUPON_LIST'] as $coupon)
            $listCoupon[] = $coupon['COUPON'];

        if($propertyCollection)
        {
            $listCouponsProperty = $propertyCollection->getItemByOrderPropertyCode(self::PROPS_COUPONS_ORDER);
            
            if($listCouponsProperty)
                $listCouponsProperty->setValue(implode(',', $listCoupon));
        }
    }
	/**
     * Функция подменяет текст "Бесплатно" на "По тарифу ТК" для доставки "Другое".
     */
    public static function replaceFreePriceText(&$arResult, &$arUserResult, &$arParams)
    {
		foreach($arResult["JS_DATA"]["DELIVERY"] as &$delivery)
		{
			if($delivery["ID"] == ID_DELIVERY_OTHER_TK)
			{
				$delivery["PRICE"] = 0.1; // DirtyHack для вывода подмененной _FORMATED цены
				$delivery["PRICE_FORMATED"] = "По тарифу ТК";
				if($delivery["CHECKED"] == "Y")
				{
					$arResult["JS_DATA"]["TOTAL"]["DELIVERY_PRICE"] = 0.1; // DirtyHack для вывода подмененной _FORMATED цены
					$arResult["JS_DATA"]["TOTAL"]["DELIVERY_PRICE_FORMATED"] = "По тарифу ТК";						
				}
			}
		}
	}
	/**
     * Функция скрывает доставку "Другое", если есть еще варианты доставки
     */
    public static function hiddenDeliveryDrugoe(&$arResult, &$arUserResult, &$arParams)
    {
		if(count($arResult["JS_DATA"]["DELIVERY"]) > 1 && array_key_exists(ID_DELIVERY_OTHER_TK, $arResult["JS_DATA"]["DELIVERY"]))
		{
			if($arResult["JS_DATA"]["DELIVERY"][ID_DELIVERY_OTHER_TK]["CHECKED"] == "Y")
			{
				$i = 0;
				foreach($arResult["JS_DATA"]["DELIVERY"] as $key => $delivery)
				{
					if($i < 1)
					{
						$arResult["JS_DATA"]["DELIVERY"][$key]["CHECKED"] = "Y";
					}else{
						unset($arResult["JS_DATA"]["DELIVERY"][$key]["CHECKED"]);
					}
					$i++;
				}
			}
			unset($arResult["JS_DATA"]["DELIVERY"][ID_DELIVERY_OTHER_TK]);
		}
	}

	
	/**
	 * Обработчик события сохранения заказа. Проверяет пользователя на принадлежность к оптовым группам перед созданием заказа, в этом случае пользователю надо авторизоваться
	 * 
	 * Если пользователь не авторизован и оптовый, предлагаем авторизоваться иначе заказ не создать
	 * 
	 * Если пользователь авторизован, но не является оптовым и вводит данные оптового клиента, выводим сообщение о том, что надо авторизоваться как оптовый пользователь
	 */
	public static function checkCertificateMoneyBeforeCreatedOrder(Event $event)
	{
			global $USER;
			$obOrder = $event->getParameter('ENTITY');		
			$basket =$obOrder->getBasket();

			$propertyFilled = false; // Свойство заполнено
			$peopertyEmpty = false; // Свойство пустое
			/**
			 * Здесь проходим по всем элементам корзины
			 * Если в корзине есть элементы с определенным свойством сертификат и свойство заполнено
			 * Заполняем свойство $propertyFilled
			 * 
			 * Если в корзине есть элементы с пустым значением, значит товар не сертификат
			 */
			foreach ($basket as $basketItem) 
			{
				$productId = $basketItem->getProductId();
				if(\MyCompany\CertificateMoney::isCertificate($productId))
					$propertyFilled = true;
				else
					$peopertyEmpty = true;
			}

			// Проверка означает что в корзине есть 2 разных товара (сертификат и не сертификат)
			// Добавляем условие, что пользователь не админ и заказ еще не создан (чтобы избежать ошибки при сохранении товара в админке)
			if($propertyFilled && $peopertyEmpty && !$USER->IsAdmin() && !$obOrder->getId())
				return new \Bitrix\Main\EventResult( 
					\Bitrix\Main\EventResult::ERROR, 
					\Bitrix\Sale\ResultError::create(
						new \Bitrix\Main\Error(
							config("order.errorCertificatesMoney")
						)
					)
				);
	}

	/**
	 * Удаляем способы доставки, в случае если в заказе есть сертификаты на деньги
	 * Удаляем службы оплаты, в случае если в заказе есть сертификаты на деньги
	 */
	public static function resetDeliveryCost(&$result)
	{
		

		$sertificateProduct = false;
		$noSertificatePriduct = false;
		foreach($result['BASKET_ITEMS'] as $itemBasket)
		{
			if(\MyCompany\CertificateMoney::isCertificate($itemBasket['PRODUCT_ID']))
				$sertificateProduct = true;
			else
				$noSertificatePriduct = true;

			if($sertificateProduct && $noSertificatePriduct)
				break;
		}

		/**
		 * Если в заказе есть только сертификаты - удаляем службы доставки
		 */
	 if($sertificateProduct && !$noSertificatePriduct)
		{
			self::modifyDeliveryResult($result["DELIVERY"], $result);
			self::modifyDeliveryResult($result["JS_DATA"]["DELIVERY"], $result);

			self::modifyPaymentResult($result["PAY_SYSTEM"], $result);
			self::modifyPaymentResult($result["JS_DATA"]["PAY_SYSTEM"], $result);

			self::deleteRelatedPropOrder($result);
		}
		
	}

	/**
	 * Удаляем привязанные r доставке свойства свойства
	 */
	public static function deleteRelatedPropOrder(&$result)
	{

		$result['ORDER_PROP']['RELATED'] = [];
		$keyPropAddress = self::getKeyPropByCode($result['JS_DATA']['ORDER_PROP']['properties'], "ADDRESS");

		if(!empty($keyPropAddress))
			unset($result['JS_DATA']['ORDER_PROP']['properties'][$keyPropAddress]);
	}
	/**
	 * Получаем ключ свойства по коду
	 */
	public static function getKeyPropByCode($arPropertyOrder, $code)
	{
		foreach($arPropertyOrder as $keyProp => $valueProp)
		{
			if($valueProp["CODE"] === $code)
				return $keyProp;
		}
		return  null;
	}

	/**
	 * Удаляет службы доставки, кроме той, которая принадлежит к сертификатам на деньги
	 *
	 * @param  array $deliveries список доставок
	 * @param  array $deliveries финальный массив arResult компонента bitrix:sale.order.ajax
	 * @return void
	 */
	private static function modifyDeliveryResult(&$deliveries, &$result)
	{
		foreach($deliveries as $deliveryId => &$delivery)
		{
			if(!in_array($deliveryId, [config("delivery.CertificateDeliveryID")]))
			{
				unset($deliveries[$deliveryId]);
			}
			else
				$deliveries[$deliveryId]["CHECKED"] = "Y";
		}
	}
	
	/**
	 * Удаляет способы оплат, кроме той, которая принадлежит к сертификатам на деньги
	 *
	 * @param  array $payments список оплат
	 * @param  array $deliveries финальный массив arResult компонента bitrix:sale.order.ajax
	 * @return void
	 */
	private static function modifyPaymentResult(&$payments, &$result)
	{
		foreach($payments as $paymentId => &$payment)
		{
			if(!in_array($payment['PAY_SYSTEM_ID'], [config("payment.CertificatePaymentID")]))
				unset($payments[$paymentId]);
		}
	}
}