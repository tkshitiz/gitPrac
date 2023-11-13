<?php


namespace PfcPublicationBundle\Controller;

use Cocur\Slugify\Slugify;
use PfcExportBundle\Common\PfcLanguage;
use Pimcore\Controller\FrontendController;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Tool;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route ("/admin/pfc-publication-export-csv")
 */
class PfcPublicationController extends FrontendController
{
    protected $slugify;
    const PUBLICATION_EXPORT_DESTINATION_TMP_FOLDER = PIMCORE_PROJECT_ROOT . '/var/tmp/publication/export/';

    /**
     * @param string $fileHandle
     *
     * @return string
     */
    protected function getCsvFile($fileHandle)
    {
        return self::PUBLICATION_EXPORT_DESTINATION_TMP_FOLDER . $fileHandle .'.csv';
    }

    /**
     * @Route("/parse", methods={"POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function parse(Request $request)
    {
        $publicationId = $request->get('allowedClassId');
        $destination = self::PUBLICATION_EXPORT_DESTINATION_TMP_FOLDER;

        $PublicationObject = DataObject\Publication::getById($publicationId, ['force'=>true]);
        if ($PublicationObject->getPublished()) {
            if ($PublicationObject->getPublished() && $PublicationObject->getPublicationName() != null) {
                if (!is_dir(self::PUBLICATION_EXPORT_DESTINATION_TMP_FOLDER)) {
                    mkdir(self::PUBLICATION_EXPORT_DESTINATION_TMP_FOLDER, 0777, true);
                }
                $user = \Pimcore\Tool\Admin::getCurrentUser();

                $userSettings = new \Pimcore\Model\User\Listing();
                $userSettings->setCondition('id = ?', [$user->getId()]);
                $userSettings->setOrderKey('name');
                $userSettings->load();

                foreach ($userSettings as $userSetting) {
                    $userId = $userSetting->getId();
                }
                $fileName = $request->get('publicationName') . '~' . $userId;
                $publicationData = $this->processCsvData($PublicationObject);
                $fileHandle = self::PUBLICATION_EXPORT_DESTINATION_TMP_FOLDER . $fileName;
                $filePath = $this->getCsvFile($fileName);
                $fileBaseName = basename($filePath);

                if (is_file($filePath)) {
//                    rename($filePath, $destination . 'publications_' . strtotime('now') . '.csv');
                    return new JsonResponse([
                        'error' => true,
                        'fileName' => $fileBaseName,
                        'message' => 'Please wait Someone is working on' . $fileBaseName . ' Publication Export  is working...'],
                        200,
                        ['content-type' => 'text/html']);
                }
                $new_csv = fopen($this->getCsvFile($fileName), "w+");

                fputcsv($new_csv, $publicationData["header"]);

                foreach ($publicationData['data'] as $file) {
                    $result = [];
                    array_walk_recursive($file, function ($item) use (&$result) {
                        $result[] = $item;
                    });
                    fputcsv($new_csv, $result);
                }

                $selectedLanguage = $publicationData["activeLanguage"];

                unset($publicationData);

                sleep(45);
                return new JsonResponse([
                    'success' => 'true',
                    'fileName' => $fileBaseName,
                    'message' => 'Success',
                    'status' => 200
                ],
                    200,
                    ['content-type' => 'text/html']
                );
            } else {
                return new JsonResponse(['error' => true, 'message' => 'Please check Publication Data before Export. Some data is messing'], 400, ['content-type' => 'text/html']);
            }
        } else {
            return new JsonResponse(['error' => true, 'message' => 'Please check Publication Data before Export.'], 400, ['content-type' => 'text/html']);
        }

    }

    /**
     * @param $publicationObject
     * @return array
     */
    public function processCsvData($publicationObject)
    {
        $systemLanguages = Tool::getValidLanguages();
        $publicationObjectFieldDefinitions = (array)$publicationObject->getClass();
        $defaultHeader = [];
        $publicationFieldNames = [];
        $publicationSettingObject = DataObject\SettingAttributes::getBySettingCode("nonMultiLanguagePublicationProduct", ['force'=>true]);
        $multilanguagePublicationSetting = DataObject\SettingAttributes::getBySettingCode("multilanguagePublicationProduct", ['force' =>true]);
        foreach($multilanguagePublicationSetting as $value){
//            print_r(get_class_methods($value));
            $multiLanguageSettingObject = explode(",", trim($value->getAttributes()));
        }

        $objectPublicationFieldNames = [];
        $productObjects = $publicationObject->getProduct();

        $activeLanguage = $this->getActiveLanguage($publicationObject, $systemLanguages);

        if (!empty($publicationObject->getProduct()) || $publicationObject->getProduct()) {
            $publicationMultiLanguageHeaderData = [];

            foreach ($publicationObjectFieldDefinitions as $key => $publicationObjectFieldDefinition) {
                if ($key == 'fieldDefinitions') {
                    foreach ($publicationObjectFieldDefinition as $key_field => $field) {
                        $publicationFieldName = $field->getName();
                        if ($publicationFieldName == "publicationName" || $publicationFieldName == "description" || $publicationFieldName == "status" || $publicationFieldName == "product" ||
                            $publicationFieldName == "en" || $publicationFieldName == "de" || $publicationFieldName == "nl" || $publicationFieldName == "it" ||
                            $publicationFieldName == "fr" || $publicationFieldName == "es" || $publicationFieldName == "cs" || $publicationFieldName == "da" || $publicationFieldName == "fi" ||
                            $publicationFieldName == "nb" || $publicationFieldName == "pl" || $publicationFieldName == "ru" || $publicationFieldName == "pt" || $publicationFieldName == "sv") {
                            continue;
                        } else {

                            $name = 'get' . ucfirst(trim($publicationFieldName));
                            if ($field->getName() == 'modelCode') {
                                $objectPublicationFieldNames[] = $field->getName();
                                $defaultHeader[] = $field->getName();
                            }
                            if ($field->getName() == 'sku') {
                                $objectPublicationFieldNames[] = $field->getName();
                                $defaultHeader[] = $field->getName();
                            }
                            if ($publicationObject->$name() == true) {
                                if (in_array($field->getName(), $multiLanguageSettingObject)) {
                                    foreach ($activeLanguage as $multiLanguageHeader) {
                                        $defaultHeader[] = $field->getName() . "_" . $multiLanguageHeader;
                                    }
                                } else {
                                    $defaultHeader[] = $field->getName();
                                }
                                $objectPublicationFieldNames [] = $publicationFieldName;
                            }
                        }
                    }
                }
            }
            unset($publicationObjectFieldDefinitions);
            unset($publicationObjectFieldDefinition);

            $data = [];
            $productData = [];
            $languageData = [];
            $skuSizeableModel = [];
            $skuSizeableModelColorValue = [];

            foreach ($productObjects as $key => $productObject) {
                $productObjectValue = DataObject\Product::getById($productObject->getId());
                if ((trim($productObject->getProductType()) == "Sku")) {
                    if (($productObject->getProductType() == "Sku") && ($productObject->getSizeCode() != "NA")) {
                        if (in_array($productObjectValue->getParent()->getModelCode(), array_unique($skuSizeableModel))) {
                            if (in_array(($productObject->getParent()->getModelCode() . $productObject->getColor1Code()), array_unique($skuSizeableModelColorValue))) {
                                continue;
                            } else {
                                $skuSizeableModelColorValue[] = $productObject->getParent()->getModelCode() . $productObject->getColor1Code();
                                $sku = substr($productObject->getSku(), 0, -1);
//                                $skuSizeableModel[] = $productObjectValue->getParent()->getModelCode();
                            }
//                            $skuSizeableModel[] = $productObjectValue->getParent()->getModelCode();
                        } else {
                            $sku = substr($productObject->getSku(), 0, -1);
                            $skuSizeableModel[] = $productObjectValue->getParent()->getModelCode();
                            $skuSizeableModelColorValue[] = $productObject->getParent()->getModelCode() . $productObject->getColor1Code();
                        }

                    } else {
                        $sku = $productObject->getSku();
                    }
//                    $sku = $productObject->getSku();
                } else {
                    $sku = null;
                }


                if (!empty($objectPublicationFieldNames)) {
                    foreach (array_unique($objectPublicationFieldNames) as $publicationProductData) {
                        $name = 'get' . ucfirst(trim($publicationProductData));

                        $multiActiveLanguage = $activeLanguage;

                        print_r($publicationProductData); echo "1";
                        if ((trim($publicationProductData) == 'modelCode')) {
                            if (($productObject->getProductType() == "Model")) {
                                $model = $productObject->getModelCode();
                                $productData [] = $model;
                                continue;
                            } else {
                                $model = $productObjectValue->getParent()->getModelCode();
                                $productData [] = $model;
                                continue;
                            }
                        }
                        echo "2";
                        //check sku with sizeable value and print only single time
                        if ((trim($publicationProductData) == "sku")) {
                            $productData [] = $sku;
                            continue;
                        } echo "3";
                        if ((trim($publicationProductData) == 'cTier1')) {
                            if (($productObject->getProductType() == "Model")) {
                                $productData [] = $productObject->$name();
                            } else {
                                if ($productObject->$name() == null) {
                                    $productData [] = $productObjectValue->getParent()->$name();
                                } else {
                                    $productData [] = $productObject->$name();
                                }
                            }
                            continue;
                        } echo "4";
                        if ((trim($publicationProductData) == 'cTier2')) {
                            if (($productObject->getProductType() == "Model")) {
                                $productData [] = $productObject->$name();
                            } else {
                                if ($productObject->$name() == null) {
                                    $productData [] = $productObjectValue->getParent()->$name();
                                } else {
                                    $productData [] = $productObject->$name();
                                }
                            }
                            continue;
                        }
                        if ((trim($publicationProductData) == 'cTier3')) {
                            if (($productObject->getProductType() == "Model")) {
                                $productData [] = $productObject->$name();
                            } else {
                                if ($productObject->$name() == null) {
                                    $productData [] = $productObjectValue->getParent()->$name();
                                } else {
                                    $productData [] = $productObject->$name();
                                }
                            }
                            continue;
                        }

                        if ((trim($publicationProductData) == 'collectionLabel')) {
                            if (($productObject->getProductType() == "Model")) {
                                $productData [] = $productObject->$name();
                            } else {
                                if ($productObject->$name() == null) {
                                    $productData [] = $productObjectValue->getParent()->$name();
                                } else {
                                    $productData [] = $productObject->$name();
                                }
                            }
                            continue;
                        }
                        if ((trim($publicationProductData) == 'productTitle')) {
                            $productData [] = $this->getActivePublicationData($productObject, $productObjectValue, $multiActiveLanguage, $languageData, $name);
                            $languageData = [];
                            continue;
                        }
                        if ((trim($publicationProductData) == 'productDescription')) {
                            $productData [] = $this->getActivePublicationData($productObject, $productObjectValue, $multiActiveLanguage, $languageData, $name);
                            $languageData = [];
                            continue;
                        }
                        if ((trim($publicationProductData) == 'countryOfOrigin')) {
                            $attributeName = 'getCountryName';
                            $productData [] = $this->getActivePublicationSingleRelationData($productObject, $productObjectValue, $multiActiveLanguage, $languageData, $name, $attributeName);
                            $languageData = [];
                            continue;
                        }
                        if ((trim($publicationProductData) == 'markSegment')) {
                            if (($productObject->getProductType() == "Model")) {
                                $productData [] = $productObject->$name();
                            } else {
                                $productData [] = $productObjectValue->getParent()->$name();
                            }
                            continue;
                        }
                        if ((trim($publicationProductData) == 'cBrand')) {
                            if (($productObject->getProductType() == "Model")) {
                                $productData [] = $productObject->$name();
                            } else {
                                if ($productObject->$name() == null) {
                                    $productData [] = $productObjectValue->getParent()->$name();
                                } else {
                                    $productData [] = $productObject->$name();
                                }
                            }
                            continue;
                        }
                        echo "5";
                        if ((trim($publicationProductData) == 'brandName')) {
                            if (($productObject->getProductType() == "Model")) {
                                $productData [] = $productObject->$name()->getName();
                            } else {
                                if ($productObject->$name() == null) {
                                    $productData [] = $productObjectValue->getParent()->$name()->getName();
                                } else {
                                    $productData [] = $productObject->$name()->getName();
                                }
                            }
                            continue;
                        }
                        if ((trim($publicationProductData) == 'gender')) {
                            if (($productObject->getProductType() == "Model")) {
                                $productData [] = $productObject->$name();
                            } else {
                                $productData [] = $productObjectValue->getParent()->$name();
                            }
                            continue;
                        }

                        if ((trim($publicationProductData) == 'similarItem')) {
                            if (($productObject->getProductType() == "Model")) {
                                $productData [] = $productObject->$name();
                            } else {
                                $productData [] = $productObjectValue->getParent()->$name();
                            }
                            continue;
                        }
                        if ((trim($publicationProductData) == 'commonItem')) {
                            if (($productObject->getProductType() == "Model")) {
                                $productData [] = $productObject->$name();
                            } else {
                                $productData [] = $productObjectValue->getParent()->$name();
                            }
                            continue;
                        }
                        if ((trim($publicationProductData) == 'licenseCode')) {
                            if (($productObject->getProductType() == "Model")) {
                                $productData [] = $productObject->$name();
                            } else {
                                $productData [] = $productObjectValue->getParent()->$name();
                            }
                            continue;
                        }
                        if ((trim($publicationProductData) == 'sizeGrid')) {
                            if (($productObject->getProductType() == "Model")) {
                                $productData [] = $productObject->$name()->getCode();
                            } else {
                                if ($productObject->$name() == null) {
                                    $productData [] = $productObjectValue->getParent()->$name()->getCode();
                                } else {
                                    $productData [] = $productObject->$name()->getCode();
                                }
                            }
                            continue;
                        }
                        if ((trim($publicationProductData) == 'cSizeGrid')) {
                            if (($productObject->getProductType() == "Model")) {
                                $productData [] = $productObject->$name();
                            } else {
                                if ($productObject->$name() == null) {
                                    $productData [] = $productObjectValue->getParent()->$name();
                                } else {
                                    $productData [] = $productObject->$name();
                                }
                            }
                            continue;
                        } echo "6";
                        if ((trim($publicationProductData) == 'sizeRange')) {
                            if (($productObject->getProductType() == "Model")) {
                                $productData [] = $productObject->$name();
                            } else {
                                $productData [] = $productObjectValue->getParent()->$name();
                            }
                            continue;
                        }
                        if ((trim($publicationProductData) == 'sizeRangeDetail')) {
                            if (($productObject->getProductType() == "Model")) {
                                $productData [] = $productObject->$name();
                            } else {
                                if ($productObject->$name() == null) {
                                    $productData [] = $productObjectValue->getParent()->$name();
                                } else {
                                    $productData [] = $productObject->$name();
                                }
                            }
                            continue;
                        }

                        if ((trim($publicationProductData) == 'hsCode')) {
                            if (($productObject->getProductType() == "Model")) {
                                $productData [] = $productObject->$name();
                            } else {
                                if ($productObject->$name() == null) {
                                    $productData [] = $productObjectValue->getParent()->$name();
                                } else {
                                    $productData [] = $productObject->$name();
                                }
                            }
                            continue;
                        }
                        if ((trim($publicationProductData) == 'eanCode')) {
                            if (($productObject->getProductType() == "Model")) {
                                $productData [] = $productObject->$name();
                            } else {
                                if ($productObject->$name() == null) {
                                    $productData [] = $productObjectValue->getParent()->$name();
                                } else {
                                    $productData [] = $productObject->$name();
                                }
                            }
                            continue;
                        }

                        if ((trim($publicationProductData) == 'productCreator')) {
                            if (($productObject->getProductType() == "Model")) {
                                $productData [] = $productObject->$name();
                            } else {
                                if ($productObject->$name() == null) {
                                    $productData [] = $productObjectValue->getParent()->$name();
                                } else {
                                    $productData [] = $productObject->$name();
                                }
                            }
                            continue;
                        }
                        if ((trim($publicationProductData) == 'productOwner')) {
                            if (($productObject->getProductType() == "Model")) {
                                $productData [] = $productObject->$name();
                            } else {
                                if ($productObject->$name() == null) {
                                    $productData [] = $productObjectValue->getParent()->$name();
                                } else {
                                    $productData [] = $productObject->$name();
                                }
                            }
                            continue;
                        }

                        if ((trim($publicationProductData) == 'skuCreator')) {
                            if (($productObject->getProductType() == "Model")) {
                                $productData [] = $productObject->$name();
                            } else {
                                if ($productObject->$name() == null) {
                                    $productData [] = $productObjectValue->getParent()->$name();
                                } else {
                                    $productData [] = $productObject->$name();
                                }
                            }
                            continue;
                        }
                        $datas= ['cProductSize','cApparelCare','cDecorationsize','cApparelGeneral','cGeneral','cDecorationMethod','cRecommendedDeco',
                            'cCountryOfOrigin','pfstoreStatus','productKeywords','styleCardPdf','inspireCardPdf','theme','cTheme','cMaterialText','marterialDrinkware',
                            'cPenInkColor','color1PmsReference','tcxColor1','hexColor2','color2PmsReference','tcxColor2','hexColor3','color3PmsReference','tcxColor3','batIncluded','cColor2',
                            'cColor3','hexColor1','extraImages','pafDocument','video','productIdentifier','prefDc','prefDcType','cCategory','materialFamily','blankIndividualProductPacking'
                            ,'decoIndividualProductPacking','giftPack','blankPackingIndividualCode','blankPackingCodeL1','blankPackingCodeL2','blankPackingQtyL1','blankPackingQtyL2','blankPackingAdditionalInfo','decoPackingIndividualCode'
                            ,'decoPackingCodeL1','decoPackingCodeL2','decoPackingQtyL1','decoPackingQtyL2','blankProductDiameter','decoPackingAdditionalInfo','blankProductHeight','blankProductWeight',
                            'blankProductWidth','defaultCartonPacking','translationStatus','launchType','initialIntroDate','projectedLaunchDate','productCreateDate','comingSoon','OnboardingStatus','poPlaced','inventoryAvailableDate','skuCreateDate','priceAvailable'
                            ,'voyageNumber','erpStatus','lifecycleStatus','translationSentBy','isMigrated','sizeList','hash','checksum','mainImage','extraImages','fabricWeight3','individualProductLength',
                            'individualProductWidth','individualProductHeight','individualProductDiameter','individualProductWeight','sizeCode','batteryOperated','cBatteryType','cBattery2Type',
                            'cBatterySize','cBattery2Size','numberOfBatteries','gots','grs','bsciFactory','bluetooth','qiWireless','wireless','rfidBlocker','rpet','windproof','umbrellaPersons','refill',
                            'cMaterialFilter','capacityMl','powerbankCapacity','insulationType','buttonDownCollar','chestPocket','easyCare','numberUsbPorts','decoTechnique','decorationStatus','skuAddons'
                            ,'isSkuAddon','seperateSellableAddon','fabricWeight','batteryTotalWeight','oekoStandard','notebookSize','paperType','cover','sleeveLength','foldedSize','umbrellaSize',
                            'bagType','checkPointFriendly','laptopCompartment','openingType','laptopTableSize','handles','withZipper','cottonQuality','insulationTimeHot','insulationTimeCold','memorySize',
                            'dishwasherSafer','solarCharger','cFabricWeight','fabricWeight2','cFabricWeight3'];
                        
                        
//                        if ((trim($publicationProductData) == 'decorationStatus')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'skuAddons')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'isSkuAddon')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//
//                        if ((trim($publicationProductData) == 'seperateSellableAddon')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }

//                        if ((trim($publicationProductData) == 'fabricWeight')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'batteryTotalWeight')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'oekoStandard')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'notebookSize')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
                        echo "7";
//                        if ((trim($publicationProductData) == 'paperType')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'cover')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }

//                        if ((trim($publicationProductData) == 'sleeveLength')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'foldedSize')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'umbrellaSize')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'bagType')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'checkPointFriendly')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }

//                        if ((trim($publicationProductData) == 'laptopCompartment')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'openingType')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'laptopTableSize')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'handles')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'withZipper')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
                        echo "8";
//                        if ((trim($publicationProductData) == 'cottonQuality')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'insulationTimeHot')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'insulationTimeCold')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'memorySize')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'dishwasherSafer')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'solarCharger')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }


//                        if ((trim($publicationProductData) == 'cFabricWeight')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'fabricWeight2')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'cfabricWeight2')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }

//                        if ((trim($publicationProductData) == 'cFabricWeight3')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
                        echo "9";
                        if ((trim($publicationProductData) == 'cBaseMaterialName2')) {
                            $name = "getBaseMaterialName2";
                            $attributeName = "getMaterialName";
                            $productData [] = $this->getActivePublicationSingleRelationData($productObject, $productObjectValue, $multiActiveLanguage, $languageData, $name, $attributeName);
                            $languageData = [];
                            $name = null;
                            continue;


//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
                        }
                        if ((trim($publicationProductData) == 'cBaseMaterialName3')) {
                            $name = "getBaseMaterialName3";
                            $attributeName = "getMaterialName";
                            $productData [] = $this->getActivePublicationSingleRelationData($productObject, $productObjectValue, $multiActiveLanguage, $languageData, $name, $attributeName);
                            $languageData = [];
                            $name = null;
                            continue;
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
                        }


//                        if ((trim($publicationProductData) == 'fabricWeight3')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'individualProductLength')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'individualProductWidth')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'individualProductHeight')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'individualProductDiameter')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'individualProductWeight')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'sizeCode')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'batteryOperated')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'cBatteryType')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'cBattery2Type')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'cBatterySize')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'cBattery2Size')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'numberOfBatteries')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'numberOfBatteries2')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'gots')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'grs')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'bsciFactory')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
                        echo "10";
//                        if ((trim($publicationProductData) == 'bluetooth')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'qiWireless')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'wireless')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'rfidBlocker')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }


//                        if ((trim($publicationProductData) == 'rpet')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'windproof')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'umbrellaPersons')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'refill')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'cMaterialFilter')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'capacityMl')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'powerbankCapacity')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'insulationType')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'buttonDownCollar')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'chestPocket')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'easyCare')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'numberUsbPorts')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'decoTechnique')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
                        echo "11";

//                        if ((trim($publicationProductData) == 'materialFamily')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'blankIndividualProductPacking')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'decoIndividualProductPacking')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'giftPack')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'blankPackingIndividualCode')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'blankPackingCodeL1')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'blankPackingCodeL2')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'blankPackingQtyL1')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'blankPackingQtyL2')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'blankPackingAdditionalInfo')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'decoPackingIndividualCode')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }

//                        if ((trim($publicationProductData) == 'decoPackingCodeL1')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
                        echo "12";
//                        if ((trim($publicationProductData) == 'decoPackingCodeL2')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'decoPackingQtyL1')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'decoPackingQtyL2')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'blankProductDiameter')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'decoPackingAdditionalInfo')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'blankProductHeight')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'blankProductLength')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }

//                        if ((trim($publicationProductData) == 'blankProductWeight')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'blankProductWidth')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'defaultCartonPacking')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'translationStatus')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'launchType')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'initialIntroDate')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'projectedLaunchDate')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }



//                        if ((trim($publicationProductData) == 'productCreateDate')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'comingSoon')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'OnboardingStatus')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'poPlaced')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'inventoryAvailableDate')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'skuCreateDate')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'priceAvailable')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'voyageNumber')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
                        echo "13";

//                        if ((trim($publicationProductData) == 'erpStatus')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'lifecycleStatus')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'translationSentBy')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'isMigrated')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'sizeList')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'hash')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'checksum')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'mainImage')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'extraImages')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }

//                        if ((trim($publicationProductData) == 'extraImages')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }

//                        if ((trim($publicationProductData) == 'pafDocument')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'video')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'productIdentifier')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'prefDc')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'prefDcType')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'cCategory')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }

                        if ((trim($publicationProductData) == 'productTitle')) {
                            $productData [] = $this->getActivePublicationData($productObject, $productObjectValue, $multiActiveLanguage, $languageData, $name);
                            $languageData = [];
                            continue;
                        }
                        if ((trim($publicationProductData) == 'sizeGrid' and $productObject->$name() != null)) {
                            $attributeName = 'getName';
                            $productData [] = $this->getActivePublicationSingleRelationData($productObject, $productObjectValue, $multiActiveLanguage, $languageData, $name, $attributeName);
                            $languageData = [];
                            continue;
                        }
                        if ((trim($publicationProductData) == 'fabricConstruction')) {
                            $attributeName = "getFabricConstruction";
                            $productData [] = $this->getActivePublicationSingleRelationData($productObject, $productObjectValue, $multiActiveLanguage, $languageData, $name, $attributeName);
                            $languageData = [];
                            continue;

                        }

                        if ((trim($publicationProductData) == 'cFabricConstruction')) {
                            $name = 'getFabricConstruction';
                            $attributeName = "getFabricConstruction";
                            $productData [] = $this->getActivePublicationSingleRelationData($productObject, $productObjectValue, $multiActiveLanguage, $languageData, $name, $attributeName);
                            $languageData = [];
                            $name = null;
                            continue;

                        }
                        if ((trim($publicationProductData) == 'fabricConstruction2')) {
                            $attributeName = "getFabricConstruction";
                            $productData [] = $this->getActivePublicationSingleRelationData($productObject, $productObjectValue, $multiActiveLanguage, $languageData, $name, $attributeName);
                            $languageData = [];
                            continue;
                        }

                        if ((trim($publicationProductData) == 'cFabricConstruction2')) {
                            $name = "getFabricConstruction2";
                            $attributeName = "getFabricConstruction";
                            $productData [] = $this->getActivePublicationSingleRelationData($productObject, $productObjectValue, $multiActiveLanguage, $languageData, $name, $attributeName);
                            $languageData = [];
                            $name = null;
                            continue;

                        }
                        if ((trim($publicationProductData) == 'fabricConstruction3')) {
                            $attributeName = "getFabricConstruction";
                            $productData [] = $this->getActivePublicationSingleRelationData($productObject, $productObjectValue, $multiActiveLanguage, $languageData, $name, $attributeName);
                            $languageData = [];
                            continue;
                        }

                        if ((trim($publicationProductData) == 'cFabricConstruction3')) {
                            $name = "getFabricConstruction3";
                            $attributeName = "getFabricConstruction";
                            $productData [] = $this->getActivePublicationSingleRelationData($productObject, $productObjectValue, $multiActiveLanguage, $languageData, $name, $attributeName);
                            $languageData = [];
                            $name = null;

                            continue;
                        }

                        if ((trim($publicationProductData) == 'license')) {
                            $attributeName = "getLicenseCode";
                            if (($productObject->getProductType() == "Model")) {
                                $productData [] = $productObject->$name()->$attributeName();
                            } else {
                                if ($productObject->$name() == null) {
                                    $productData [] = $productObjectValue->getParent()->$name()->$attributeName();
                                } else {
//                                    $productData [] = $productObject->$name()->$attributeName();
                                    $productData [] = null;
                                }
                            }

                            continue;
                        }

                        if ((trim($publicationProductData) == 'baseMaterialName2')) {
                            $attributeName = "getMaterialName";
                            $productData [] = $this->getActivePublicationSingleRelationData($productObject, $productObjectValue, $multiActiveLanguage, $languageData, $name, $attributeName);
                            $languageData = [];
                            continue;
                        }

//                        if ((trim($publicationProductData) == 'hexColor1')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }

//                        if ((trim($publicationProductData) == 'color1PmsReference')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }

//                        if ((trim($publicationProductData) == 'tcxColor1')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }

//                        if ((trim($publicationProductData) == 'hexColor2')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }

//                        if ((trim($publicationProductData) == 'color2PmsReference')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'tcxColor2')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'hexColor3')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
                        echo "14";
//                        if ((trim($publicationProductData) == 'color3PmsReference')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'tcxColor3')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'batIncluded')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }


                        if ((trim($publicationProductData) == 'color2Code')) {
                            $attributeName = "getMainColorDescription";
                            $productData [] = $productObject->getCColor2();
                            $languageData = [];
                            continue;
                        }
//                        if ((trim($publicationProductData) == 'cColor2')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'cColor3')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
                        if ((trim($publicationProductData) == 'color3Code')) {
                            $attributeName = "getMainColorDescription";
                            $productData [] = $productObject->getCColor3();
                            $languageData = [];
                            continue;
                        }
                        if ((trim($publicationProductData) == 'baseMaterialName3')) {
                            $attributeName = "getMaterialName";
                            $productData [] = $this->getActivePublicationSingleRelationData($productObject, $productObjectValue, $multiActiveLanguage, $languageData, $name, $attributeName);
                            $languageData = [];
                            continue;
                        }
                        if ((trim($publicationProductData) == 'fabricDescription')) {
                            $productData [] = $this->getActivePublicationData($productObject, $productObjectValue, $multiActiveLanguage, $languageData, $name);
                            $languageData = [];
                            continue;
                        }
                        if ((trim($publicationProductData) == 'fabricDescription2')) {
                            $productData [] = $this->getActivePublicationData($productObject, $productObjectValue, $multiActiveLanguage, $languageData, $name);
                            $languageData = [];
                            continue;
                        }
                        if ((trim($publicationProductData) == 'fabricDescription3')) {
                            $productData [] = $this->getActivePublicationData($productObject, $productObjectValue, $multiActiveLanguage, $languageData, $name);
                            $languageData = [];
                            continue;
                        }
                        if ((trim($publicationProductData) == 'materialText')) {
                            $productData [] = $this->getActivePublicationData($productObject, $productObjectValue, $multiActiveLanguage, $languageData, $name);
                            $languageData = [];
                            continue;
                        }

//                        if ((trim($publicationProductData) == 'cMaterialText')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'marterialDrinkware')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
                        echo "15";
//                        if ((trim($publicationProductData) == 'cPenInkColor')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'penInkColor')) {
//                            $attributeName = "getPenInkColorDescription";
//                            $productData [] = $this->getActivePublicationSingleRelationData($productObject, $productObjectValue, $multiActiveLanguage, $languageData, $name, $attributeName);
//                            $languageData = [];
//                            continue;
////                            if (($productObject->getProductType() == "Model")) {
////                                $productData [] = $productObject->$name();
////                            } else {
////                                if ($productObject->$name() == null) {
////                                    $productData [] = $productObjectValue->getParent()->$name();
////                                } else {
////                                    $productData [] = $productObject->$name();
////                                }
////                            }
////                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'productKeywords')) {
//                            $productData [] = $this->getActivePublicationData($productObject, $productObjectValue, $multiActiveLanguage, $languageData, $name);
//                            $languageData = [];
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'styleCardPdf')) {
//                            $productData [] = $this->getActivePublicationData($productObject, $productObjectValue, $multiActiveLanguage, $languageData, $name);
//                            $languageData = [];
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'inspireCardPdf')) {
//                            $productData [] = $this->getActivePublicationData($productObject, $productObjectValue, $multiActiveLanguage, $languageData, $name);
//                            $languageData = [];
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'theme')) {
//                            $attributeName = "getTheme";
//                            $productData [] = $this->getActivePublicationSingleRelationData($productObject, $productObjectValue, $multiActiveLanguage, $languageData, $name, $attributeName);
//                            $languageData = [];
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'cTheme')) {
//                            if ($productObject->$name != null || $productObject->getProductType() == "Model") {
//                                $productData[] = $productObject->$name();
//                            } elseif ($productObject->getProductType() != "Model") {
//                                $productData[] = $productObjectValue->getParent()->$name();
//                            }
//                            continue;
//                        }
           //yah baata
       
//                        if ((trim($publicationProductData) == 'cProductSize')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'cApparelCare')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'cDecorationsize')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'cApparelGeneral')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'cGeneral')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'cDecorationMethod')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = str_replace(',', ';', $productObject->$name());
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = str_replace(',', ';', $productObjectValue->getParent()->$name());
//                                } else {
//                                    $productData [] = str_replace(',', ';', $productObject->$name());
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'cRecommendedDeco')) {
//                            if (($productObject->getProductType() == "Model")) {
//                                $productData [] = str_replace(',', ';', $productObject->$name());
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = str_replace(',', ';', $productObjectValue->getParent()->$name());
//                                } else {
//                                    $productData [] = str_replace(',', ';', $productObject->$name());
//                                }
//                            }
//                            continue;
//                        }
//                        if ((trim($publicationProductData) == 'cCountryOfOrigin')) {
//                            if ($productObject->getProductType() == "Model") {
//                                $productData[] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        } echo "16";
//                        if ((trim($publicationProductData) == 'pfstoreStatus')) {
//                            if ($productObject->getProductType() == "Model") {
//                                $productData[] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }

                        if ((trim($publicationProductData) == 'baseMaterial1')) {
                            $attributeName = "getMaterialDetail";
                            if (($productObject->getProductType() == "Model")) {
                                foreach ($multiActiveLanguage as $language) {
                                    foreach ($productObject->$name() as $productMultiValue) {
                                        $baseMaterial1Object = DataObject\MaterialDetail::getById($productMultiValue->getElementId());
                                        $publicationMultiData[] = $baseMaterial1Object->$attributeName(PfcLanguage::getLanguageCode($language));
                                    }
                                    $languageData[] = implode(";", array_filter($publicationMultiData));
                                    $publicationMultiData = [];
                                    if ($languageData) {
                                        $productData [] = $languageData;
                                    } else {
                                        $productData [] = null;
                                    }
                                    $languageData = [];
                                }
//                            $languageData = [];
                                continue;
                            } else {
                                if ($productObject->$name() == null) {
                                    foreach ($multiActiveLanguage as $language) {
                                        foreach ($productObjectValue->getParent()->$name() as $productMultiValue) {
                                            $baseMaterial1Object = DataObject\MaterialDetail::getById($productMultiValue->getElementId());
                                            $publicationMultiData[] = $baseMaterial1Object->$attributeName(PfcLanguage::getLanguageCode($language));
                                        }
                                        $languageData[] = implode(";", array_filter($publicationMultiData));
                                        $publicationMultiData = [];
                                        if ($languageData) {
                                            $productData [] = $languageData;
                                        } else {
                                            $productData [] = null;
                                        }
                                        $languageData = [];
                                    }

                                } else {
                                    foreach ($multiActiveLanguage as $language) {
                                        foreach ($productObject->$name() as $productMultiValue) {
                                            $baseMaterial1Object = DataObject\MaterialDetail::getById($productMultiValue->getElementId());
                                            $publicationMultiData[] = $baseMaterial1Object->$attributeName(PfcLanguage::getLanguageCode($language));
                                        }
                                        $languageData[] = implode(";", array_filter($publicationMultiData));
                                        $publicationMultiData = [];
                                        if ($languageData) {
                                            $productData [] = $languageData;
                                        } else {
                                            $productData [] = null;
                                        }
                                        $languageData = [];
                                    }
                                }
                            }
                            continue;
                        }
                        if ((trim($publicationProductData) == 'cBaseMaterial1')) {
                            $name = "getBaseMaterial1";
                            $attributeName = "getMaterialDetail";
                            if (($productObject->getProductType() == "Model")) {
                                foreach ($multiActiveLanguage as $language) {
                                    foreach ($productObject->$name() as $productMultiValue) {
                                        $baseMaterial1Object = DataObject\MaterialDetail::getById($productMultiValue->getElementId());
                                        $publicationMultiData[] = $baseMaterial1Object->$attributeName(PfcLanguage::getLanguageCode($language));
                                    }
                                    $languageData[] = implode(";", array_filter($publicationMultiData));
                                    $publicationMultiData = [];
                                    if ($languageData) {
                                        $productData [] = $languageData;
                                    } else {
                                        $productData [] = null;
                                    }
                                    $languageData = [];
                                }
//                            $languageData = [];
                                continue;
                            } else {
                                if ($productObject->$name() == null) {
                                    foreach ($multiActiveLanguage as $language) {
                                        foreach ($productObjectValue->getParent()->$name() as $productMultiValue) {
                                            $baseMaterial1Object = DataObject\MaterialDetail::getById($productMultiValue->getElementId());
                                            $publicationMultiData[] = $baseMaterial1Object->$attributeName(PfcLanguage::getLanguageCode($language));
                                        }
                                        $languageData[] = implode(";", array_filter($publicationMultiData));
                                        $publicationMultiData = [];
                                        if ($languageData) {
                                            $productData [] = $languageData;
                                        } else {
                                            $productData [] = null;
                                        }
                                        $languageData = [];
                                    }

                                } else {
                                    foreach ($multiActiveLanguage as $language) {
                                        foreach ($productObject->$name() as $productMultiValue) {
                                            $baseMaterial1Object = DataObject\MaterialDetail::getById($productMultiValue->getElementId());
                                            $publicationMultiData[] = $baseMaterial1Object->$attributeName(PfcLanguage::getLanguageCode($language));
                                        }
                                        $languageData[] = implode(";", array_filter($publicationMultiData));
                                        $publicationMultiData = [];
                                        if ($languageData) {
                                            $productData [] = $languageData;
                                        } else {
                                            $productData [] = null;
                                        }
                                        $languageData = [];
                                    }
                                }
                            }
                            $name = null;
                            continue;
                        }
                        echo "17";
                        if ((trim($publicationProductData) == 'baseMaterial2')) {
                            $attributeName = "getMaterialDetail";
                            if (($productObject->getProductType() == "Model")) {
                                foreach ($multiActiveLanguage as $language) {
                                    foreach ($productObject->$name() as $productMultiValue) {
                                        $baseMaterial2Object = DataObject\MaterialDetail::getById($productMultiValue->getElementId());
                                        $publicationMultiData[] = $baseMaterial2Object->$attributeName(PfcLanguage::getLanguageCode($language));
                                    }
                                    $languageData[] = implode(";", array_filter($publicationMultiData));
                                    $publicationMultiData = [];
                                    if ($languageData) {
                                        $productData [] = $languageData;
                                    } else {
                                        $productData [] = null;
                                    }
                                    $languageData = [];
                                }
                            } else {
                                if ($productObject->$name() == null) {
                                    foreach ($multiActiveLanguage as $language) {
                                        foreach ($productObjectValue->getParent()->$name() as $productMultiValue) {
                                            $baseMaterial2Object = DataObject\MaterialDetail::getById($productMultiValue->getElementId());
                                            $publicationMultiData[] = $baseMaterial2Object->$attributeName(PfcLanguage::getLanguageCode($language));
                                        }
                                        $languageData[] = implode(";", array_filter($publicationMultiData));
                                        $publicationMultiData = [];
                                        if ($languageData) {
                                            $productData [] = $languageData;
                                        } else {
                                            $productData [] = null;
                                        }
                                        $languageData = [];
                                    }

                                } else {
                                    foreach ($multiActiveLanguage as $language) {
                                        foreach ($productObject->$name() as $productMultiValue) {
                                            $baseMaterial2Object = DataObject\MaterialDetail::getById($productMultiValue->getElementId());
                                            $publicationMultiData[] = $baseMaterial2Object->$attributeName(PfcLanguage::getLanguageCode($language));
                                        }
                                        $languageData[] = implode(";", array_filter($publicationMultiData));
                                        $publicationMultiData = [];
                                        if ($languageData) {
                                            $productData [] = $languageData;
                                        } else {
                                            $productData [] = null;
                                        }
                                        $languageData = [];
                                    }
                                }

                            }
//                            $languageData = [];
                            continue;
                        }

                        if ((trim($publicationProductData) == 'cBaseMaterial2')) {
                            $name = "getBaseMaterial2";
                            $attributeName = "getMaterialDetail";
                            if (($productObject->getProductType() == "Model")) {
                                foreach ($multiActiveLanguage as $language) {
                                    foreach ($productObject->$name() as $productMultiValue) {
                                        $baseMaterial2Object = DataObject\MaterialDetail::getById($productMultiValue->getElementId());
                                        $publicationMultiData[] = $baseMaterial2Object->$attributeName(PfcLanguage::getLanguageCode($language));
                                    }
                                    $languageData[] = implode(";", array_filter($publicationMultiData));
                                    $publicationMultiData = [];
                                    if ($languageData) {
                                        $productData [] = $languageData;
                                    } else {
                                        $productData [] = null;
                                    }
                                    $languageData = [];
                                }
                            } else {
                                if ($productObject->$name() == null) {
                                    foreach ($multiActiveLanguage as $language) {
                                        foreach ($productObjectValue->getParent()->$name() as $productMultiValue) {
                                            $baseMaterial2Object = DataObject\MaterialDetail::getById($productMultiValue->getElementId());
                                            $publicationMultiData[] = $baseMaterial2Object->$attributeName(PfcLanguage::getLanguageCode($language));
                                        }
                                        $languageData[] = implode(";", array_filter($publicationMultiData));
                                        $publicationMultiData = [];
                                        if ($languageData) {
                                            $productData [] = $languageData;
                                        } else {
                                            $productData [] = null;
                                        }
                                        $languageData = [];
                                    }

                                } else {
                                    foreach ($multiActiveLanguage as $language) {
                                        foreach ($productObject->$name() as $productMultiValue) {
                                            $baseMaterial2Object = DataObject\MaterialDetail::getById($productMultiValue->getElementId());
                                            $publicationMultiData[] = $baseMaterial2Object->$attributeName(PfcLanguage::getLanguageCode($language));
                                        }
                                        $languageData[] = implode(";", array_filter($publicationMultiData));
                                        $publicationMultiData = [];
                                        if ($languageData) {
                                            $productData [] = $languageData;
                                        } else {
                                            $productData [] = null;
                                        }
                                        $languageData = [];
                                    }
                                }

                            }
                            $name = null;
//                            $languageData = [];
                            continue;
                        }
//                        if ((trim($publicationProductData) == 'cBaseMaterial2')) {
//                            if ($productObject->getProductType() == "Model") {
//                                $productData[] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }
                        echo "18";
                        if ((trim($publicationProductData) == 'baseMaterial3')) {
                            $attributeName = "getMaterialDetail";
                            if (($productObject->getProductType() == "Model")) {
                                foreach ($multiActiveLanguage as $language) {
                                    foreach ($productObject->$name() as $productMultiValue) {
                                        $baseMaterial3Object = DataObject\MaterialDetail::getById($productMultiValue->getElementId());
                                        $publicationMultiData[] = $baseMaterial3Object->$attributeName(PfcLanguage::getLanguageCode($language));
                                    }
                                    $languageData[] = implode(";", array_filter($publicationMultiData));
                                    $publicationMultiData = [];
                                    if ($languageData) {
                                        $productData [] = $languageData;
                                    } else {
                                        $productData [] = null;
                                    }
                                    $languageData = [];
                                }
                                $languageData = [];
                            } else {
                                if ($productObject->$name() == null) {
                                    foreach ($multiActiveLanguage as $language) {
                                        foreach ($productObjectValue->getParent()->$name() as $productMultiValue) {
                                            $baseMaterial3Object = DataObject\MaterialDetail::getById($productMultiValue->getElementId());
                                            $publicationMultiData[] = $baseMaterial3Object->$attributeName(PfcLanguage::getLanguageCode($language));
                                        }
                                        $languageData[] = implode(";", array_filter($publicationMultiData));
                                        $publicationMultiData = [];
                                        if ($languageData) {
                                            $productData [] = $languageData;
                                        } else {
                                            $productData [] = null;
                                        }
                                        $languageData = [];
                                    }

                                } else {
                                    foreach ($multiActiveLanguage as $language) {
                                        foreach ($productObject->$name() as $productMultiValue) {
                                            $baseMaterial3Object = DataObject\MaterialDetail::getById($productMultiValue->getElementId());
                                            $publicationMultiData[] = $baseMaterial3Object->$attributeName(PfcLanguage::getLanguageCode($language));
                                        }
                                        $languageData[] = implode(";", array_filter($publicationMultiData));
                                        $publicationMultiData = [];
                                        if ($languageData) {
                                            $productData [] = $languageData;
                                        } else {
                                            $productData [] = null;
                                        }
                                        $languageData = [];
                                    }
                                }
                            }
                            continue;
                        }
                        if ((trim($publicationProductData) == 'cBaseMaterial3')) {
                            $name = "getBaseMaterial3";
                            $attributeName = "getMaterialDetail";
                            if (($productObject->getProductType() == "Model")) {
                                foreach ($multiActiveLanguage as $language) {
                                    foreach ($productObject->$name() as $productMultiValue) {
                                        $baseMaterial3Object = DataObject\MaterialDetail::getById($productMultiValue->getElementId());
                                        $publicationMultiData[] = $baseMaterial3Object->$attributeName(PfcLanguage::getLanguageCode($language));
                                    }
                                    $languageData[] = implode(";", array_filter($publicationMultiData));
                                    $publicationMultiData = [];
                                    if ($languageData) {
                                        $productData [] = $languageData;
                                    } else {
                                        $productData [] = null;
                                    }
                                    $languageData = [];
                                }
                                $languageData = [];
                            } else {
                                if ($productObject->$name() == null) {
                                    foreach ($multiActiveLanguage as $language) {
                                        foreach ($productObjectValue->getParent()->$name() as $productMultiValue) {
                                            $baseMaterial3Object = DataObject\MaterialDetail::getById($productMultiValue->getElementId());
                                            $publicationMultiData[] = $baseMaterial3Object->$attributeName(PfcLanguage::getLanguageCode($language));
                                        }
                                        $languageData[] = implode(";", array_filter($publicationMultiData));
                                        $publicationMultiData = [];
                                        if ($languageData) {
                                            $productData [] = $languageData;
                                        } else {
                                            $productData [] = null;
                                        }
                                        $languageData = [];
                                    }

                                } else {
                                    foreach ($multiActiveLanguage as $language) {
                                        foreach ($productObject->$name() as $productMultiValue) {
                                            $baseMaterial3Object = DataObject\MaterialDetail::getById($productMultiValue->getElementId());
                                            $publicationMultiData[] = $baseMaterial3Object->$attributeName(PfcLanguage::getLanguageCode($language));
                                        }
                                        $languageData[] = implode(";", array_filter($publicationMultiData));
                                        $publicationMultiData = [];
                                        if ($languageData) {
                                            $productData [] = $languageData;
                                        } else {
                                            $productData [] = null;
                                        }
                                        $languageData = [];
                                    }
                                }
                            }
                            $name = null;
                            continue;
                        }

//                        if ((trim($publicationProductData) == 'cBaseMaterial3')) {
//                            if ($productObject->getProductType() == "Model") {
//                                $productData[] = $productObject->$name();
//                            } else {
//                                if ($productObject->$name() == null) {
//                                    $productData [] = $productObjectValue->getParent()->$name();
//                                } else {
//                                    $productData [] = $productObject->$name();
//                                }
//                            }
//                            continue;
//                        }

                        echo "19";
                        if ((trim($publicationProductData) == 'disallowedCountries')) {//
                            $attributeName = "getCountryIsoCode";
//                            $productData [] = $this->getActivePublicationMultiRelationData($productObject, $name, $attributeName);

                            if ($productObject->getProductType() == "Model") {
                                foreach ($productObject->$name() as $productMultiValue) {
                                    $publicationMultiData[] = $productMultiValue->$attributeName();
                                }
                            } elseif ($productObject->getProductType() != "Model") {
                                foreach ($productObjectValue->getParent()->$name() as $productMultiValue) {
                                    $publicationMultiData[] = $productMultiValue->$attributeName();
                                }
                            }
                            if ($publicationMultiData) {
                                $productData [] = implode(",", array_filter($publicationMultiData));
                            } else {
                                $productData [] = null;
                            }
                            $publicationMultiData = [];
                            continue;
                        }
                        if ((trim($publicationProductData) == 'cartonPackings')) {
                            $attributeName = "getCartonPackingCode";
//                            $productData [] = $this->getActivePublicationMultiRelationData($productObject, $name, $attributeName);

                            foreach ($productObject->$name() as $productMultiValue) {
                                $publicationMultiData[] = $productMultiValue->$attributeName();
                            }
                            $languageData[] = implode(";", array_filter($publicationMultiData));
                            $publicationMultiData = [];
                            if ($languageData) {
                                $productData [] = $languageData;
                            } else {
                                $productData [] = null;
                            }
                            $languageData = [];
                            continue;

                        }
                        if ((trim($publicationProductData) == 'Decorations')) {
                            foreach ($multiActiveLanguage as $language) {
                                foreach ($productObject->$name() as $productMultiValue) {
                                    $publicationMultiData[] = $productMultiValue->getMethod()->getDecoMethod(PfcLanguage::getLanguageCode($language));
                                }
                                $languageData[] = implode(";", array_filter($publicationMultiData));
                                $publicationMultiData = [];
                                if ($languageData) {
                                    $productData [] = $languageData;
                                } else {
                                    $productData [] = null;
                                }
                                $languageData = [];
                            }
                            $languageData = [];
                            continue;
                        }

                        if (((trim($publicationProductData) == 'complianceDocuments'))) {
                            $attributeName = "getComplianceCode";
                            $productData [] = $this->getActivePublicationMultiRelationData($productObject, $name, $attributeName);
                            $languageData = [];
                            continue;
                        }

                        if (((trim($publicationProductData) == 'extraImagesList'))) {
                            $extraImageListData = [];
                            if ($productObject->$name() != null) {
                                foreach ($productObject->$name() as $extraImageList) {
                                    $extraImageListData[] = $extraImageList->getImageName() . ',' . $extraImageList->getImage()->getUrl();
                                }
                            }
                            if ($extraImageListData) {
                                $productData [] = implode(";", array_filter($extraImageListData));
                            } else {
                                $productData [] = null;
                            }
                            continue;
                        }
                        if (((trim($publicationProductData) == 'InternalImage'))) {
                            $internalImageListData = [];

                            if ($productObject->$name() != null) {
                                foreach ($productObject->$name() as $internalImageList) {
                                    $internalImageListData[] = basename($internalImageList);
                                }
                            }
                            if ($internalImageListData) {
                                $productData [] = implode(";", array_filter($internalImageListData));
                            } else {
                                $productData [] = null;
                            }
                            continue;
                        }
                        if (((trim($publicationProductData) == 'addDocument'))) {
                            $addDocumentListData = [];

                            if ($productObject->$name() != null) {
                                foreach ($productObject->$name() as $addDocumentList) {
                                    $addDocumentListData[] = basename($addDocumentList);
                                }
                            }
                            if ($addDocumentListData) {
                                $productData [] = implode(";", array_filter($addDocumentListData));
                            } else {
                                $productData [] = null;
                            }
                            continue;
                        }

                        $productData [] = $productObject->$name();
                    }
                } else {
                    $productData[] = $productObject->getModelCode();
                    $productData[] = $productObject->getSku();

                }
                $data [] = $productData;

                $productData = [];
                unset($productObject);
                unset($productObjectValue);
            }

            if (empty($activeLanguage)) {
                $activeLanguage[] = "en";
            }
//            $languageValue = array_intersect($languages, $activeLanguage);
        } else {
            $data [] = [];
            $activeLanguage = null;

        }
        echo "20";
        return ["header" => array_unique($defaultHeader), "data" => $data, "activeLanguage" => $activeLanguage, "resultData" => array_merge([$defaultHeader], $data)];

    }

    /**
     * @param $productObjects
     * @param $multiActiveLanguage
     * @param $languageData
     * @param $name
     * @return mixed
     */
    public
    function getActivePublicationData($productObjects, $productObjectValue, $multiActiveLanguage, $languageData, $name)
    {

        if ($productObjects->$name() != null || $productObjects->getProductType() == "Model") {
            foreach ($multiActiveLanguage as $language) {
                $languageData[] = $productObjects->$name(PfcLanguage::getLanguageCode($language));
            }
            return $languageData;
        } elseif ($productObjects->getProductType() != "Model") {
            foreach ($multiActiveLanguage as $language) {
                $languageData[] = $productObjectValue->getParent()->$name(PfcLanguage::getLanguageCode($language));
            }
            return $languageData;
        }

    }

    /**
     * @param $productObjects
     * @param $multiActiveLanguage
     * @param $languageData
     * @param $name
     * @param $attributeName
     * @return mixed
     */
    public
    function getActivePublicationSingleRelationData($productObjects, $productObjectValue, $multiActiveLanguage, $languageData, $name, $attributeName)
    {
        if ($productObjects->getProductType() == "Model") {
            foreach ($multiActiveLanguage as $language) {
                if ($productObjects->$name() == null) {
                    $languageData[] = null;
                } else {
                    $languageData[] = $productObjects->$name()->$attributeName(PfcLanguage::getLanguageCode($language));
                }
            }
            return $languageData;
        } elseif ($productObjects->getProductType() != "Model") {
            foreach ($multiActiveLanguage as $language) {
                if ($productObjectValue->getParent()->$name() == null) {
                    $languageData[] = null;
                } else {
                    $languageData[] = $productObjectValue->getParent()->$name()->$attributeName(PfcLanguage::getLanguageCode($language));
                }
            }
            return $languageData;
        }
    }

    /**
     * @param $productObjects
     * @param $name
     * @param $attributeName
     * @return mixed
     */
    public
    function getActivePublicationMultiRelationData($productObjects, $name, $attributeName)
    {
        foreach ($productObjects->$name() as $productMultiValue) {
            $publicationMultiData[] = $productMultiValue->$attributeName();
        }
        if ($publicationMultiData) {
            $productData [] = implode(",", $publicationMultiData);
        } else {
            $productData [] = null;
        }
        return $productData;

    }

    /**
     * @Route("/csvDownload", methods={"GET"})
     *
     * @param Request $request
     *
     * @return BinaryFileResponse
     *
     * @return JsonResponse
     */
    public
    function download(Request $request)
    {
        $this->slugify = new Slugify();
        $fileName = $this->slugify->slugify($request->get('publicationName'));
        $fileHandle = \Pimcore\File::getValidFilename($fileName);

        if ($_GET['file'] != null) {
            $fileName = $this->slugify->slugify($_GET['file']);
            $fileHandle = \Pimcore\File::getValidFilename($fileName);
        }

        $csvFile = $this->getCsvFile($fileHandle);
        if (file_exists($csvFile)) {

            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
            header("Content-Type: application/force-download");
            header('Content-Disposition: attachment; filename="' . basename($csvFile) . '"');
            header('Content-Transfer-Encoding: binary');
            header('Expires: 0');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Pragma: public');
            header('Content-Length: ' . filesize($csvFile));
            ob_end_clean();
            flush();

            readfile($csvFile);
            exit;
        }

        throw $this->createNotFoundException('CSV file not found');
    }

    /**
     * @param array $languageNames
     * @param $publicationObject
     * @return array
     */
    public
    function getActiveLanguage($publicationObject, $systemLanguages)
    {
        $activeLanguage = [];
        foreach ($systemLanguages as $systemLanguage) {
            $name = 'get' . ($systemLanguage);
            if ($publicationObject->$name() == true) {
                $activeLanguage [] = $systemLanguage;
            }
        }
        if ($activeLanguage) {
            return $activeLanguage;
        } else {
            $activeLanguage[] = "en";
            return $activeLanguage;
        }
    }

    /**
     * @param $string
     * @return mixed
     */
    public function slugify($string)
    {
        return $this->slugify->slugify($string);
    }

}
