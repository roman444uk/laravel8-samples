<?php

namespace App\Services\Wildberries;

enum WbAttributes: string
{
    case TITLE = 'Наименование';
    case DESCRIPTION = 'Описание';
    case KEYWORDS = 'Ключевые слова';
    case CATEGORY = 'Предмет';
    case COUNTRY = 'Страна производства';

    case COLOR = 'Цвет';
    case COMPOSITION = 'Состав';
    case BRAND = 'Бренд';
    case SIZE = 'Размер';
    case SIZE_RU = 'Рос. размер';
    case SIZE_RU_FULL = 'Российский размер';

    case WEIGHT = 'Вес товара без упаковки (г)';
    case WIDTH = 'Ширина упаковки';
    case HEIGHT = 'Высота упаковки';
    case LENGTH = 'Длина упаковки';
    
    case TNVED = 'ТНВЭД';
}
