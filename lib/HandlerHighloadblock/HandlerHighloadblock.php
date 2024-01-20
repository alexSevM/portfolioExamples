<?
namespace MyCompany;

use Bitrix\Main\Loader;
use Bitrix\Highloadblock\HighloadBlockTable;
/**
 * Класс для работы с хайлоад блоками в Bitrix.
 */
class HandlerHighloadblock
{
    public $enitityClass;

    /**
     * Создает новый экземпляр класса.
     *
     * @param string $nameHlBlock Название хайлоад блока.
     */
    function __construct(string $nameHlBlock)
    {
        Loader::includeModule("highloadblock");
        $this->enitityClass = $this->getHLClassByName($nameHlBlock);
    }

    /**
     * Получает класс сущности хайлоад блока по его названию.
     *
     * @param string $nameHlBlock Название хайлоад блока.
     * @return object|null Класс сущности хайлоад блока или null, если не удалось получить.
     */
    public function getHighloadBlockIdByName($nameHlBlock)
    {
        $dbRes = HighloadBlockTable::getList([
            "filter" => [
                "NAME" => $nameHlBlock
            ],
            "select" => [
                "ID",
            ],
            "limit" => 1,
            "cache" => [
                "ttl" => 86400
            ]
        ])->fetch();

        return ($dbRes ? $dbRes["ID"] : 0);
    }

    public function getHLClassByName(string $name)
    {
        $id = $this->getHighloadBlockIdByName($name);
        if ($id == 0)
            return "";
        return $this->getHLClassById($id);
    }

    public function getHLClassById(int $idHLBlock) : string
    {
        if(!$hlblock = HighloadBlockTable::getById($idHLBlock)->fetch())
            return "";
        $entity = HighloadBlockTable::compileEntity($hlblock);
        return $entity->getDataClass();
    }
    /**
     * Получает список записей из хайлоад блока по заданному фильтру.
     *
     * @param array $arFilter Фильтр для выборки записей.
     * @return object Список записей из хайлоад блока.
     */
    public function getList($arFilter)
    {
        $res = [];
        $rsData = $this->enitityClass::getList($arFilter);
       
        while($el = $rsData->fetch())
            $res[] = $el;
        
        return $res;
  
    }

    /**
     * Добавляет новую запись в хайлоад блок.
     *
     * @param array $arFields Поля новой записи.
     * @return int|bool Идентификатор добавленной записи или false в случае ошибки.
     */
    public function add( $arFields)
    {
        return $this->enitityClass::add($arFields);
    }

    /**
     * Обновляет существующую запись в хайлоад блоке.
     *
     * @param int $ID Идентификатор записи для обновления.
     * @param array $arFields Новые значения полей записи.
     * @return bool Результат обновления записи (true - успешно, false - ошибка).
     */
    public function update($ID, $arFields)
    {
        return $this->enitityClass::update($ID, $arFields);
    }

    /**
     * Удаляет запись из хайлоад блока.
     *
     * @param int $ID Идентификатор записи для удаления.
     * @return bool Результат удаления записи (true - успешно, false - ошибка).
     */
    public function delete($ID)
    {
        return $this->enitityClass::delete($ID);
    }

    /**
     * Получает запись из хайлоад блока по ее идентификатору.
     *
     * @param int $ID Идентификатор записи.
     * @return object Запись из хайлоад блока или null, если запись не найдена.
     */
    public function getById($ID)
    {
        return $this->enitityClass::getById($ID);
    }

    /**
     * Возвращает количество записей в хайлоад блоке, соответствующих заданному фильтру.
     *
     * @param array $arFilter Фильтр для выборки записей.
     * @return int Количество записей в хайлоад блоке.
     */
    public function getListCount($arFilter)
    {
        return count($this->enitityClass::getList($arFilter)->fetchAll());
    }

    /**
     * Возвращает список полей хайлоад блока.
     *
     * @return array Список полей хайлоад блока.
     */
    public function getFields()
    {
        return $this->enitityClass::getFields();
    }

    /**
     * Возвращает класс данных сущности хайлоад блока.
     *
     * @return object Класс данных сущности хайлоад блока.
     */
    public function getEntityDataClass()
    {
        return $this->enitityClass::getEntityDataClass();
    }
}


/**Класс `HlBlock` предоставляет методы для работы с хайлоад блоками в Bitrix. Он содержит следующие методы:

- `__construct`: Создает новый экземпляр класса `HlBlock` и подключает модуль `highloadblock`.
- `getHLClassByName`: Получает класс сущности хайлоад блока по его названию.
- `getList`: Получает список записей из хайлоад блока по заданному фильтру.
- `add`: Добавляет новую запись в хайлоад блок.
- `update`: Обновляет существующую запись в хайлоад блоке.
- `delete`: Удаляет запись из хайлоад блока.
- `getById`: Получает запись из хайлоад блока по ее идентификатору.
- `getListCount`: Получает количество записей в хайлоад блоке по заданному фильтру.
- `getFields`: Получает поля хайлоад блока.
- `getEntityDataClass`: Получает класс данных сущности хайлоад блока.

Этот класс предоставляет удобные методы для работы с хайлоад блоками в Bitrix.
 */