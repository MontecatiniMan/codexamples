<?php

declare(strict_types=1);

namespace frontend\controllers;

use common\helpers\HomeViewRegisterer;
use common\models\db\RefHome;
use common\yii\web\Controller;
use frontend\controllers\hotel\HotelActionFavourite;
use frontend\controllers\hotel\HotelActionIndex;
use frontend\controllers\hotel\HotelActionMap;
use frontend\models\home\card\HomeCardRepository;
use frontend\models\home\card\query\HomeQueryLoader;
use frontend\models\home\opinions\HomeOpinionRepository;
use frontend\views\hotel\card\HomeCard_ViewFile;
use Yii;
use yii\helpers\Url;
use yii\web\NotFoundHttpException;

/**
 * Контроллер отелей.
 *
 * @author Mike Shatunov <mixasic@yandex.ru>
 */
class HotelController extends Controller {

	public const ACTION_FAVORITE  = 'favorite';
	public const ACTION_INDEX     = 'index';
	public const ACTION_MAP       = 'map';
	public const ACTION_VIEW      = 'view';
	public const ACTION_VIEW_SLUG = 'view-slug';

	public const PARAM_ID = 'id';

	/**
	 * {@inheritdoc}
	 *
	 * @author Mike Shatunov <mixasic@yandex.ru>
	 */
	public function actions(): array {
		return [
			static::ACTION_FAVORITE    => HotelActionFavourite   ::class,
			static::ACTION_INDEX       => HotelActionIndex       ::class,
			static::ACTION_MAP         => HotelActionMap         ::class,
		];
	}

	/**
	 * Просмотр отеля по slug.
	 *
	 * @param string $slug Идентификатор отеля slug
	 *
	 * @return string
	 *
	 * @author Mike Shatunov <mixasic@yandex.ru>
	 */
	public function actionViewSlug(string $slug): string {
		$dbHome = RefHome::getModelFromSlug($slug);
		if (null === $dbHome) {
			throw new NotFoundHttpException;
		}

		return $this->actionView((string)$dbHome->serial_number);
	}

	/**
	 * Просмотр отеля по номеру.
	 *
	 * @param string $number Номер отеля
	 *
	 * @return string
	 *
	 * @author Mike Shatunov <mixasic@yandex.ru>
	 */
	public function actionView(string $number): string {
		$dbHome = RefHome::getModelBySerial($number);
		if (null === $dbHome) {
			throw new NotFoundHttpException;
		}

		// -- Увеличиваем количество просмотров
		$dbHome->increaseViews();
		// -- -- -- --

		// -- Загружаем параметры для поиска номеров, тарифов и цен
		$loader = new HomeQueryLoader($dbHome->id);
		$query  = $loader->getQuery(Yii::$app->request->queryParams);
		// -- -- -- --

		// -- Получаем данные об отеле
		$repository = new HomeCardRepository;
		$home       = $repository->get($dbHome->id, $query);
		// -- -- -- --

		// -- Получаем отзывы
		$opinions = (new HomeOpinionRepository)->get($dbHome->id);
		// -- -- -- --

		// -- Записываем данные о посещении
		(new HomeViewRegisterer)->register($dbHome->id);
		// -- -- -- --

		$this->view->title                = $home->name;
		Yii::$app->params['appContainer'] = 'appHotel';// Задаем класс для контейнера, так у нас работает JS (если не указать, все сломается)

		return $this->renderContent(new HomeCard_ViewFile($home, $query, $opinions));
	}

	/**
	 * Получение маршрута на просмотр отеля.
	 *
	 * @param RefHome $home Идентификатор отеля
	 *
	 * @return array
	 *
	 * @author Mike Shatunov <mixasic@yandex.ru>
	 */
	public static function getViewRoute(RefHome $home): array {
		$urlParam = [
			$home->getType()->slug,
			$home->getGeo()->getCountry()->slug,
			$home->getGeo()->getCity()->slug,
			$home->serial_number,
			$home->slug,
		];

		return ['/' . implode('/', $urlParam)];
	}

	/**
	 * Получение ссылки на отправку запросов на избранное.
	 *
	 * @return string
	 *
	 * @author Mike Shatunov <mixasic@yandex.ru>
	 */
	public static function getFavouriteUrl(): string {
		return Url::to(static::getUrlRoute(static::ACTION_FAVORITE)) . '?' . HotelActionFavourite::PARAM_HOME_ID . '=';
	}
}
