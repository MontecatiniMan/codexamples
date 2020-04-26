<?php

declare(strict_types=1);

namespace frontend\models\sanatorium\single;

use common\helpers\DistanceHelper;
use common\models\db\RefCreditCard;
use common\models\db\RefDocuments;
use common\models\db\RefFacility;
use common\models\db\RefFacilityCategory;
use common\models\db\RefHome;
use common\models\db\RefHomeBranch;
use common\models\db\RefHomeFacility;
use common\models\db\RefHomeLnkCreditCard;
use common\models\db\RefHomeLnkDocuments;
use common\models\db\RefHomeLnkFacility;
use common\models\db\RefHomeTblChildren;
use common\models\db\RefHomeTreatmentProgram;
use common\models\db\RefPhotoCategory;
use common\models\Picture;
use common\modules\disease\models\RefDisease;
use common\modules\disease\models\RefHomeLnkDisease;
use common\modules\disease\models\RefHomeTherapy;
use common\modules\gallery\GalleryBehavior;
use common\modules\temperature\models\RegTemperatureMonth;
use common\services\home\grade\HomeGradeRepository;
use common\yii\base\BaseObject;
use common\yii\helpers\DateHelper;
use frontend\helpers\PictureHelper;
use frontend\models\sanatorium\single\models\Sanatorium;
use frontend\models\sanatorium\single\models\SanatoriumBranch;
use frontend\models\sanatorium\single\models\SanatoriumCreditCard;
use frontend\models\sanatorium\single\models\SanatoriumDocuments;
use frontend\models\sanatorium\single\models\SanatoriumFacilities;
use frontend\models\sanatorium\single\models\SanatoriumFacility;
use frontend\models\sanatorium\single\models\SanatoriumFacilityCategory;
use frontend\models\sanatorium\single\models\SanatoriumFacilityPaid;
use frontend\models\sanatorium\single\models\SanatoriumProgram;
use frontend\models\sanatorium\single\models\SanatoriumRules;
use frontend\models\sanatorium\single\models\SanatoriumRulesChildren;
use frontend\models\sanatorium\single\models\SanatoriumTherapyBase;
use frontend\models\sanatorium\single\models\SanatoriumTherapyProcedure;
use frontend\models\sanatorium\single\models\SanatoriumTherapyProfile;
use frontend\models\sanatorium\single\models\SanatoriumWeather;
use frontend\models\sanatorium\single\models\SanatoriumWeatherTemperature;
use frontend\models\sanatorium\single\query\SanatoriumQuery;
use frontend\repository\sanatorium\SanatoriumRoomRepository;
use Ramsey\Uuid\Uuid;
use yii\db\ActiveQuery;
use yii\helpers\ArrayHelper;

/**
 * Репозиторий для получения данных по конкретному санаторию.
 *
 * @author Mike Shatunov <mixasic@yandex.ru>
 *
 */
class Repository extends BaseObject {
	/**
	 * Получение данных.
	 *
	 * @param string          $sanatoriumId Идентификатор санатория
	 * @param SanatoriumQuery $query        Параметры для получения тарифов и цен
	 *
	 * @return Sanatorium
	 *
	 * @author Mike Shatunov <mixasic@yandex.ru>
	 */
	public function get(string $sanatoriumId, SanatoriumQuery $query): Sanatorium {
		$dbSanatorium = RefHome::getModelFromId($sanatoriumId);
		$sanatorium = new Sanatorium;

		// -- Заполняем основную информацию
		$sanatorium->id              = $dbSanatorium->id;
		$sanatorium->name            = $dbSanatorium->name;
		$sanatorium->serial          = $dbSanatorium->serial_number;
		$sanatorium->images          = $dbSanatorium->getImages();
		$sanatorium->address         = $dbSanatorium->getInfo()->address;
		$sanatorium->distanceToBeach = DistanceHelper::format($dbSanatorium->beach_distance);
		$sanatorium->roomsCount      = $dbSanatorium->getInfo()->rooms_count;
		$sanatorium->internet        = $dbSanatorium->getInternet();
		$sanatorium->parking         = $dbSanatorium->getParking();
		$sanatorium->textDescription = $dbSanatorium->getDescription()->description;
		$sanatorium->textRestaurant  = $dbSanatorium->getDescription()->restaurant;
//		$sanatorium->textImportant   = $dbSanatorium->getDescription()->important;
		$sanatorium->treatment       = $query->treatment;

		// -- Заполняем принимаемые карты
		$sanatorium->creditCards     = $this->getCreditCards($sanatorium->id);
		// -- -- -- --

		// -- Заполняем правила проживания
		$sanatorium->rules               = new SanatoriumRules;
		$sanatorium->rules->checkInFrom  = $this->formatTime($dbSanatorium->getInfo()->check_in_from);
		$sanatorium->rules->checkInTo    = $this->formatTime($dbSanatorium->getInfo()->check_in_to);
		$sanatorium->rules->checkOutFrom = $this->formatTime($dbSanatorium->getInfo()->check_out_from);
		$sanatorium->rules->checkOutTo   = $this->formatTime($dbSanatorium->getInfo()->check_out_to);

		$sanatorium->documents = $this->getSanatoriumDocuments($sanatorium->id);

		$sanatorium->rules->children = $this->getChildrenRules($sanatoriumId);
		// -- -- -- --

		// -- Заполняем основные услуги
		$sanatorium->facilities = $this->getFacilities($sanatoriumId);
		// -- -- -- --

		// -- Заполняем платные услуги
		$sanatorium->facilitiesPaid = $this->getFacilitiesPaid($sanatoriumId);
		// -- -- -- --

		// -- Поверяем платный ли интернет
		$sanatorium->hasFreeInternet = (null !== $sanatorium->internet) ? (0.0 === floatval($sanatorium->internet->internet_price)) : true;
		// -- -- -- --

		// -- Поверяем отмечен ли ресторан в списках услуг
		$sanatorium->hasRestaurant = $this->getFacilitiesRestaurant($sanatoriumId);
		// -- -- -- --

		// -- Заполняем данными об оценках
		$sanatorium->grade = (new HomeGradeRepository)->get($sanatoriumId);
		// -- -- -- --

		// -- Заполняем процедурами лечебную базу
		$sanatorium->therapyBase             = new SanatoriumTherapyBase();
		$sanatorium->therapyBase->procedures = $this->getTherapyProcedures($sanatoriumId);
		$sanatorium->therapyBase->profiles   = $this->getTherapyProfiles($sanatoriumId);
		// -- -- -- --

		// -- Заполнить лечебные программы
		$sanatorium->programs = $this->getSanatoriumPrograms($sanatoriumId);
		// -- -- -- --

		// -- Заполняем координаты
		$sanatorium->latitude  = $dbSanatorium->getGeo()->latitude;
		$sanatorium->longitude = $dbSanatorium->getGeo()->longitude;
		// -- -- -- --

		// Заполняем корпуса и данные о погоде
		$sanatorium->branches = $this->getBranches($sanatoriumId);
		$sanatorium->weather  = $this->getWeather($dbSanatorium->getGeo()->city_id);
		// -- -- -- --

		// -- Заполнить номера
		$sanatorium->rooms = (new SanatoriumRoomRepository())->get($sanatoriumId, $query);
		// -- -- -- --

		// -- Минимальное количестов заселяющихся
		$occupancy = [0];
		foreach ($sanatorium->rooms as $room) {
			$occupancy[] = $room->occupancy;
		}
		$sanatorium->occupancy = max($occupancy);
		// -- -- -- --

		// -- Минимальная цена
		$sanatorium->minPrice = $this->getMinPrice($sanatorium->rooms);
		// -- -- -- --

		return $sanatorium;
	}

	/**
	 * Минимальная цена
	 *
	 * @param array $rooms Номера
	 *
	 * @return int
	 *
	 * @author Mike Shatunov <mixasic@yandex.ru>
	 */
	private function getMinPrice(array $rooms): int {
		$tariffs = [];
		foreach ($rooms as $room) {
			foreach ($room->tariffs as $tariff) {
				$tariffs[] = $tariff->price;
			}
		}
		if ([] !== $tariffs) {
			$result = min($tariffs);

			return $result;
		}

		return 0;
	}
	/**
	 * Получение услуг
	 *
	 * @param string $sanatoriumId Идентификатор санатория
	 *
	 * @return SanatoriumFacilities
	 *
	 * @author Mike Shatunov <mixasic@yandex.ru>
	 */
	private function getFacilities(string $sanatoriumId): SanatoriumFacilities {
		$result = new SanatoriumFacilities;

		// -- Получаем данные из базы
		$relationsDb = RefHomeLnkFacility::find()
			->select(RefHomeLnkFacility::ATTR_FACILITY_ID)
			->where([RefHomeLnkFacility::ATTR_HOME_ID => $sanatoriumId]);

		$facilitiesDb = RefFacility::find()
			->where([
				RefFacility::ATTR_ID           => $relationsDb,
				RefFacility::ATTR_DELETE_STAMP => DateHelper::ZERO_DATETIME,
			])
			->all()
		;/** @var RefFacility[] $facilitiesDb */

		$categoriesDb = RefFacilityCategory::find()
			->select(RefFacilityCategory::ATTR_NAME)
			->where([
				RefFacilityCategory::ATTR_ID => ArrayHelper::getColumn($facilitiesDb, RefFacility::ATTR_CATEGORY_ID),
				RefFacilityCategory::ATTR_DELETE_STAMP => DateHelper::ZERO_DATETIME,
			])
			->indexBy(RefFacilityCategory::ATTR_ID)
			->column();
		// -- -- -- --

		// -- Преобразовываем данные из базы в модели
		foreach ($categoriesDb as $categoryDbId => $categoryDbName) {
			$category = new SanatoriumFacilityCategory;
			$category->name = $categoryDbName;

			$result->categories[$categoryDbId] = $category;
		}

		foreach ($facilitiesDb as $facilityDb) {
			if (false === array_key_exists($facilityDb->category_id, $result->categories)) {
				continue;
			}

			$facility       = new SanatoriumFacility;
			$facility->id   = $facilityDb->id;
			$facility->name = $facilityDb->name;
			$facility->icon = $facilityDb->icon;

			if (true === $facilityDb->is_important) {
				$result->important[] = $facility;
			}

			$result->categories[$facilityDb->category_id]->facilities[] = $facility;
		}
		// -- -- -- --

		return $result;
	}

	/**
	 * Получение правил для детей
	 *
	 * @param string $sanatoriumId Идентификатор санатория
	 *
	 * @return SanatoriumRulesChildren[]
	 *
	 * @author Mike Shatunov <mixasic@yandex.ru>
	 */
	private function getChildrenRules(string $sanatoriumId): array {
		$result = [];

		$childrenRulesDb = RefHomeTblChildren::find()
			->where([RefHomeTblChildren::ATTR_HOME_ID => $sanatoriumId])
			->all()
		;/** @var RefHomeTblChildren $childrenRuleDb */

		$discountTypes = RefHomeTblChildren::getDiscountTypes();

		foreach ($childrenRulesDb as $childrenRuleDb) {
			$childrenRule                  = new SanatoriumRulesChildren;
			$childrenRule->ageFrom         = $childrenRuleDb->age_from;
			$childrenRule->ageTo           = $childrenRuleDb->age_to;
			$childrenRule->discountAmount  = $childrenRuleDb->discount_amount;
			$childrenRule->discountMeasure = $discountTypes[$childrenRuleDb->discount_measure];

			$result[] = $childrenRule;
		}

		return $result;
	}

	/**
	 * Форматирование времени
	 *
	 * @param string $time Время
	 *
	 * @return string
	 *
	 * @author Mike Shatunov <mixasic@yandex.ru>
	 */
	private function formatTime(string $time): string {
		return implode(':', array_slice(explode(':', $time), 0, 2));
	}

	/**
	 * Проверка услуг на наличие отметки ресторана
	 *
	 * @param string $sanatoriumId Идентификатор санатория
	 *
	 * @return bool
	 *
	 * @author Mike Shatunov <mixasic@yandex.ru>
	 */
	private function getFacilitiesRestaurant(string $sanatoriumId): bool {
		$result = false;
		$facilitiesId = RefFacility::find()
			->select(RefFacility::ATTR_ID)
			->where([RefFacility::ATTR_DELETE_STAMP => DateHelper::ZERO_DATETIME])
			->andWhere(['like', 'name', 'Ресторан'])
			->column();

		$relations = RefHomeLnkFacility::find()
			->where([RefHomeLnkFacility::ATTR_HOME_ID => $sanatoriumId])
			->andWhere(['in', RefHomeLnkFacility::ATTR_FACILITY_ID, $facilitiesId])
			->count();

		if ($relations > 0) {
			$result = true;
		}

		return $result;
	}

	/**
	 * Получение лечебных процедур
	 *
	 * @param string $sanatoriumId Идентфикатор санатория
	 *
	 * @return RefHomeTherapy[]
	 *
	 * @author Mike Shatunov <mixasic@yandex.ru>
	 */
	private function getTherapyProcedures(string $sanatoriumId): array {
		$result = [];

		$proceduresDb = RefHomeTherapy::find()
			->where([
				RefHomeTherapy::ATTR_HOME_ID      => $sanatoriumId,
				RefHomeTherapy::ATTR_DELETE_STAMP => DateHelper::ZERO_DATETIME,
			])
			->all()
		;/** @var RefHomeTherapy[]  $proceduresDb */

		foreach ($proceduresDb as $procedureDb) {
			$procedure              = new SanatoriumTherapyProcedure;
			$procedure->id          = $procedureDb->id;
			$procedure->name        = $procedureDb->name;
			$procedure->description = $procedureDb->description;
			$procedure->image       = ($procedureDb->image_base_url . '/' . $procedureDb->image_path);

			$result[] = $procedure;
		}

		return $result;
	}

	/**
	 * Получение профилей лечения
	 *
	 * @param string $sanatoriumId Идентификатор санатория
	 *
	 * @return array
	 *
	 * @author Mike Shatunov <mixasic@yandex.ru>
	 */
	private function getTherapyProfiles(string $sanatoriumId): array {
		$result = [];

		$relationsDb = RefHomeLnkDisease::find()
			->select(RefHomeLnkDisease::ATTR_DISEASE_ID)
			->where([RefHomeLnkDisease::ATTR_HOME_ID => $sanatoriumId])
		;

		$profilesDb = RefDisease::findAll([
			RefDisease::ATTR_ID           => $relationsDb,
			RefDisease::ATTR_DELETE_STAMP => DateHelper::ZERO_DATETIME,
		]);/** @var RefDisease[] $profilesDb */

		foreach ($profilesDb as $profileDb) {
			$profile       = new SanatoriumTherapyProfile;
			$profile->id   = $profileDb->id;
			$profile->name = $profileDb->name;

			$result[] = $profile;
		}

		return $result;
	}

	/**
	 * Получение списка принимаемых кредитных карт
	 *
	 * @param string $sanatoriumId Идентификатор санатория
	 *
	 * @return SanatoriumCreditCard[]
	 *
	 * @author Mike Shatunov <mixasic@yandex.ru>
	 */
	private function getCreditCards(string $sanatoriumId): array {
		$result = [];

		$relationsDb = RefHomeLnkCreditCard::find()
			->select(RefHomeLnkCreditCard::ATTR_CARD_ID)
			->where([
				RefHomeLnkCreditCard::ATTR_HOME_ID  => $sanatoriumId,
				RefHomeLnkCreditCard::ATTR_ACCEPTED => true,
			])
		;

		$cardsDb = RefCreditCard::findAll([
			RefCreditCard::ATTR_ID           => $relationsDb,
			RefCreditCard::ATTR_DELETE_STAMP => DateHelper::ZERO_DATETIME,
		]);/** @var RefCreditCard[] $cardsDb */

		foreach ($cardsDb as $cardDb) {
			$card       = new SanatoriumCreditCard;
			$card->name = $cardDb->name;
			$card->icon = $cardDb->icon;

			$result[] = $card;
		}

		return $result;
	}

	/**
	 * Получение списка требуемых для проживания документов.
	 *
	 * @param string $sanatoriumId Идентификатор санатория
	 *
	 * @return SanatoriumDocuments
	 *
	 * @author Mike Shatunov <mixasic@yandex.ru>
	 */
	private function getSanatoriumDocuments(string $sanatoriumId): SanatoriumDocuments {
		$sanatoriumDocuments = new SanatoriumDocuments();

		// -- Документы взрослых для проживания
		$adultForLivingIds = RefHomeLnkDocuments::find()
			->select(RefHomeLnkDocuments::ATTR_DOCUMENT_ID)
			->where([
				RefHomeLnkDocuments::ATTR_HOME_ID              => $sanatoriumId,
				RefHomeLnkDocuments::ATTR_ADULT                => true,
				RefHomeLnkDocuments::ATTR_NECESSARY_FOR_LIVING => true,
			])
		;
		$sanatoriumDocuments->adultForLiving = $this->getDocumentsByIds($adultForLivingIds);
		// -- -- -- --

		// -- Документы взрослых для лечения
		$adultForTreatmentIds = RefHomeLnkDocuments::find()
			->select(RefHomeLnkDocuments::ATTR_DOCUMENT_ID)
			->where([
				RefHomeLnkDocuments::ATTR_HOME_ID                => $sanatoriumId,
				RefHomeLnkDocuments::ATTR_ADULT                  => true,
				RefHomeLnkDocuments::ATTR_REQUIRED_FOR_TREATMENT => true,
			])
		;
		$sanatoriumDocuments->adultForTreatment = $this->getDocumentsByIds($adultForTreatmentIds);
		// -- -- -- --

		// -- Документы для лечения детей
		$childForTreatmentIds = RefHomeLnkDocuments::find()
			->select(RefHomeLnkDocuments::ATTR_DOCUMENT_ID)
			->where([
				RefHomeLnkDocuments::ATTR_HOME_ID                => $sanatoriumId,
				RefHomeLnkDocuments::ATTR_CHILD                  => true,
				RefHomeLnkDocuments::ATTR_REQUIRED_FOR_TREATMENT => true,
			])
		;
		$sanatoriumDocuments->childForTreatment = $this->getDocumentsByIds($childForTreatmentIds);
		// -- -- -- --

		// -- Документы для проживания детей
		$childForLivingIds = RefHomeLnkDocuments::find()
			->select(RefHomeLnkDocuments::ATTR_DOCUMENT_ID)
			->where([
				RefHomeLnkDocuments::ATTR_HOME_ID               => $sanatoriumId,
				RefHomeLnkDocuments::ATTR_CHILD                 => true,
				RefHomeLnkDocuments::ATTR_NECESSARY_FOR_LIVING  => true,
			])
		;
		$sanatoriumDocuments->childForLiving = $this->getDocumentsByIds($childForLivingIds);
		// -- -- -- --

		return $sanatoriumDocuments;
	}

	/**
	 * Получение документов по Идентификаторам.
	 *
	 * @param $ids ActiveQuery Запрос идентификторов
	 *
	 * @return string[]
	 *
	 * @author Mike Shatunov <mixasic@yandex.ru>
	 */
	public function getDocumentsByIds(ActiveQuery $ids): array {
		return RefDocuments::find()
			->select(RefDocuments::ATTR_NAME)
			->where([
				RefDocuments::ATTR_ID           => $ids,
				RefDocuments::ATTR_DELETE_STAMP => DateHelper::ZERO_DATETIME,
			])->column()
			;
	}

	/**
	 * Получение корпусов
	 *
	 * @param string $sanatoriumId Идентификатор санатория
	 *
	 * @return SanatoriumBranch[]
	 *
	 * @author Mike Shatunov <mixasic@yandex.ru>
	 */
	private function getBranches(string $sanatoriumId): array {
		$result = [];

		$branchesDb = RefHomeBranch::findAll([
			RefHomeBranch::ATTR_HOME_ID      => $sanatoriumId,
			RefHomeBranch::ATTR_DELETE_STAMP => DateHelper::ZERO_DATETIME,
		]);

		foreach ($branchesDb as $branchDb) {
			$branch = new SanatoriumBranch;
			$branch->id        = $branchDb->id;
			$branch->name      = $branchDb->name;
//			$branch->latitude  = $branchDb->latitude;
//			$branch->longitude = $branchDb->longitude;

			$result[] = $branch;
		}

		return $result;
	}

	/**
	 * Получение данных о погоде
	 *
	 * @param string $cityId Идентификатор города, в котором находится санаторий
	 *
	 * @return SanatoriumWeather[]
	 *
	 * @author Mike Shatunov <mixasic@yandex.ru>
	 */
	private function getWeather(string $cityId): array {
		$result = [];

		// -- Если uuid города дефолтный, то прекращаем поиск температур
		if (Uuid::NIL === $cityId) {
			return $result;
		}
		// -- -- -- --

		$temperaturesDb = RegTemperatureMonth::find()
			->where([
				RegTemperatureMonth::ATTR_CITY_ID => $cityId,
			])
			->orderBy([
				RegTemperatureMonth::ATTR_MONTH        => SORT_ASC,
				RegTemperatureMonth::ATTR_UPDATE_STAMP => SORT_DESC,
			])
			->all()
		;/** @var RegTemperatureMonth $temperaturesDb */

		foreach ($temperaturesDb as $temperatureDb) {
			$temperature = new SanatoriumWeather();
			$temperature->month = $temperatureDb::getMonths()[$temperatureDb->month];

			$temperature->tempAir      = new SanatoriumWeatherTemperature;
			$temperature->tempAir->min = $temperatureDb->air_min;
			$temperature->tempAir->avg = $temperatureDb->air_avg;
			$temperature->tempAir->max = $temperatureDb->air_max;

			$temperature->tempWater      = new SanatoriumWeatherTemperature;
			$temperature->tempWater->min = $temperatureDb->water_min;
			$temperature->tempWater->avg = $temperatureDb->water_avg;
			$temperature->tempWater->max = $temperatureDb->water_max;

			$result[] = $temperature;
		}

		return $result;
	}

	/**
	 * Получение платных услуг
	 *
	 * @param string $sanatoriumId Идентификатор санатория
	 *
	 * @return SanatoriumFacilityPaid[]
	 *
	 * @author Mike Shatunov <mixasic@yandex.ru>
	 */
	private function getFacilitiesPaid(string $sanatoriumId): array {
		$result = [];

		$facilitiesDb = RefHomeFacility::find()
			->where([
				RefHomeFacility::ATTR_HOME_ID => $sanatoriumId,
				RefHomeFacility::ATTR_DELETE_STAMP => DateHelper::ZERO_DATETIME,
			])
			->all()
		;/** @var RefHomeFacility[] $facilitiesDb */

		foreach ($facilitiesDb as $facilityDb) {
			$facility              = new SanatoriumFacilityPaid;
			$facility->id          = $facilityDb->id;
			$facility->name        = $facilityDb->name;
			$facility->short       = $facilityDb->short;
			$facility->description = $facilityDb->description;
			$facility->price       = $facilityDb->price_amount;
			$facility->measure     = $facilityDb->price_unit;

			if (0 !== count($facilityDb->getImages())) {
				$facility->image = $facilityDb->getImages()[0];
			}

			$facility->images = $facilityDb->getImages();

			$result[] = $facility;
		}

		return $result;
	}

	/**
	 * Получить лечебные программы
	 *
	 * @param string $sanatoriumId Идентификатор санатория
	 *
	 * @return array
	 *
	 * @author Mike Shatunov <mixasic@yandex.ru>
	 */
	private function getSanatoriumPrograms(string $sanatoriumId): array {
		$models = RefHomeTreatmentProgram::findAll([RefHomeTreatmentProgram::ATTR_HOME_ID => $sanatoriumId]);

		$programs = [];

		foreach ($models as $model) {
			$program                         = new SanatoriumProgram();
			$program->id                     = $model->id;
			$program->title                  = $model->name_public;
			$program->description            = $model->description;
			$program->standing               = $model->min_booking_days;
			$program->minAge                 = $model->min_age;
			$program->maxAge                 = $model->max_age;
			$program->needCard               = $model->require_health_card;
			$program->whatGivesGrogram       = $model->what_gives_program;
			$program->whom                   = $model->whom;
			$program->goal                   = $model->goal;
			$program->contraindications      = $model->contraindications;
			$program->recommendedBookingDays = $model->recommended_booking_days;
			$program->maxBookingDays         = $model->max_booking_days;
			$program->price                  = $model->price;
			$program->analysis               = $model->require_analysis_age;
			$program->minBookingDays               = $model->min_booking_days;
			$program->minPregrant            = $model->min_pregnant_stage;
			$program->images                 = $this->getImages($model->id);
			$program->mainImage              = $program->getMainImage()->get(RefPhotoCategory::PHOTO_SIZE_735);

			foreach (PictureHelper::getPicturesBySize($program->images, RefPhotoCategory::PHOTO_SIZE_735) as $key => $image) {
				$program->images[$key] = $image;
			}

			$programs[] = $program;
		}

		return $programs;
	}

	/**
	 * Получение ссылкок на все изображения
	 *
	 * @param string $sanatoriumId Идентификатор санатория
	 *
	 * @return Picture[]
	 *
	 * @author Mike Shatunov <mixasic@yandex.ru>
	 */
	public function getImages(string $sanatoriumId): array {
		$category = RefPhotoCategory::findOne([RefPhotoCategory::ATTR_ENTITY_ID => $sanatoriumId]);
		$result   = [];

		if (null !== $category) {
			$behavior = $category->getBehavior(RefPhotoCategory::BEHAVIOR_GALLERY);/** @var GalleryBehavior $behavior */
			$images   = $behavior->getImages();

			foreach ($images as $image) {
				$result[] = new Picture($image);
			}
		}

		return $result;
	}
}
