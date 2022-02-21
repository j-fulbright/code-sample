<?php

namespace App\Http\Controllers;

use App\Commands\CommandBusInterface;
use App\Domain\Objects\ChargeEntryObject;
use App\Domain\Objects\ChargeLineItemObject;
use App\Domain\Objects\CreditLineItemObject;
use App\Domain\Objects\LocationObject;
use App\Domain\Objects\PaymentEntryObject;
use App\Domain\Objects\PaymentLineItemObject;
use App\Domain\Services\DatabaseService;
use App\Domain\Services\EmailBlastService;
use App\Domain\Services\FamilyCommunicationService;
use App\Domain\Services\GatewayService;
use App\Domain\Services\PaymentApplyServiceInterface;
use App\Domain\Services\PaymentProcessorService;
use App\Domain\Services\UserLocationsService;
use App\Enums\AppSource;
use App\Models\AuditLogEntry;
use App\Repositories\AccountsRepository;
use App\Repositories\AuditLogRepositoryInterface;
use App\Repositories\ChargeCategoriesRepositoryInterface;
use App\Repositories\ChargeEntriesRepositoryInterface;
use App\Repositories\ChargeLineItemsRepositoryInterface;
use App\Repositories\CreditLineItemsRepositoryInterface;
use App\Repositories\CurrencySymbolsRepository;
use App\Repositories\CurrencySymbolsRepositoryInterface;
use App\Repositories\DataTransferObjects\GatewayTransactionDto;
use App\Repositories\FamilyLedgerRepositoryInterface;
use App\Repositories\FamiliesRepositoryInterface;
use App\Repositories\GatewaysRepository;
use App\Repositories\GatewaysRepositoryInterface;
use App\Repositories\GiftCertificateSettingsRepository;
use App\Repositories\LocationsRepositoryInterface;
use App\Repositories\PaymentEntriesRepositoryInterface;
use App\Repositories\PaymentLineItemsRepositoryInterface;
use App\Repositories\ProgramsRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Http\Request;
use DB;
use App\Domain\Objects\OnlineActivityObject;
use App\Repositories\OnlineActivitiesRepositoryInterface;

class GiftCertificateController extends ApiController
{
    private $familyLedgerRepository;
    private $chargeEntriesRepository;
    private $locationRepository;
    private $familiesRepository;
    private $gatewayRepository;
    private $paymentEntriesRepository;
    private $paymentLineItemsRepository;
    private $paymentApplyService;
    private $auditLogRepository;
    private $currencySymbolsRepository;
    private $chargeLineItemsRepository;
    private $programsRepository;
    private $chargeCategoriesRepository;
    private $creditLineItemsRepository;
    private $onlineActivitiesRepository;

    public function __construct(
        FamilyLedgerRepositoryInterface $familyLedgerRepository,
        ChargeEntriesRepositoryInterface $chargeEntriesRepository,
        LocationsRepositoryInterface $locationsRepository,
        FamiliesRepositoryInterface $familiesRepository,
        GatewaysRepositoryInterface $gatewaysRepository,
        PaymentEntriesRepositoryInterface $paymentEntriesRepository,
        PaymentLineItemsRepositoryInterface $paymentLineItemsRepository,
        PaymentApplyServiceInterface $paymentApplyService,
        AuditLogRepositoryInterface $auditLogRepository,
        CurrencySymbolsRepositoryInterface $currencySymbolsRepository,
        ChargeLineItemsRepositoryInterface $chargeLineItemsRepository,
        ProgramsRepositoryInterface $programsRepository,
        ChargeCategoriesRepositoryInterface $chargeCategoriesRepository,
        CreditLineItemsRepositoryInterface $creditLineItemsRepository,
        OnlineActivitiesRepositoryInterface $onlineActivitiesRepository,
        CommandBusInterface $bus
    ) {
        $this->familyLedgerRepository = $familyLedgerRepository;
        $this->chargeEntriesRepository = $chargeEntriesRepository;
        $this->locationRepository = $locationsRepository;
        $this->familiesRepository = $familiesRepository;
        $this->gatewayRepository = $gatewaysRepository;
        $this->paymentEntriesRepository = $paymentEntriesRepository;
        $this->paymentLineItemsRepository = $paymentLineItemsRepository;
        $this->paymentApplyService = $paymentApplyService;
        $this->auditLogRepository = $auditLogRepository;
        $this->currencySymbolsRepository = $currencySymbolsRepository;
        $this->chargeLineItemsRepository = $chargeLineItemsRepository;
        $this->programsRepository = $programsRepository;
        $this->chargeCategoriesRepository = $chargeCategoriesRepository;
        $this->creditLineItemsRepository = $creditLineItemsRepository;
        $this->onlineActivitiesRepository = $onlineActivitiesRepository;
        parent::__construct($bus);
    }

    public function processCard(Request $request, $accountName)
    {
        require_once(__DIR__ . '/../../../../autoload.php');

        DatabaseService::connectByAccountName($accountName);

        $transactionStatus = $request->input('successful');
        $transactionId = $request->input('transactionId');
        $gatewayId = intval($request->route('gatewayId'));
        $familyId = intval($request->route('familyId'));

        $errors = [];
        if ($familyId > 0 && $transactionStatus && !empty($gatewayId)) {
            if ($request->isMethod('post')) {
                return $this->respondWithMessage('Success');
            } else {
                return view('office-portal.payment-form-landing-page', [
                    'transactionId' => $transactionId,
                ]);
            }
        }
        if ($request->isMethod('post')) {
            return $this->respondWithErrors('Failed to update autopay', $errors);
        } else {
            return view('office-portal.payment-form-landing-page');
        }
    }

    public function getSPF(Request $request, $accountName, $familyId)
    {
        require_once(__DIR__ . '/../../../../autoload.php');

        DatabaseService::connectByAccountName($accountName);

        $location = UserLocationsService::getCurrentLocation(true);
        $locationId = $location->getId();
        $askToStoreCard = false;
        $gatewayType = $request->route('gatewayType');

        $giftCertificateSettingsRepository = new GiftCertificateSettingsRepository();
        $giftCertificateSettings = $giftCertificateSettingsRepository->get();

        $originalFamilyId = $familyId;
        $familyId = ($familyId === "0" ? $giftCertificateSettings->getFamilyId() : $familyId);
        $staffId = 0;
        $fromOfficePortal = false;

        $accountRepository = new AccountsRepository();
        $account = $accountRepository->getByAccountName($accountName);
        $accountId = $account->getId();

        $requireCvv = !$fromOfficePortal;
        $amount = number_format(floatval($request->route('amount')), 2, '.', '');

        $gatewayService = new GatewayService($accountRepository);
        $gatewaysRepository = new GatewaysRepository();

        if (!empty($gatewayType) && $gatewayType === 'cp') {
            $gateway = $gatewaysRepository->getCardPresentGateway();
            $askToStoreCard = false;
            if (empty($gateway)) {
                $gateway = $gatewaysRepository->getCardNotPresentGateway();
                $gatewayType = 'cnp';
            }
        } else {
            $gateway = $gatewaysRepository->getCardNotPresentGateway();
            $gatewayType = 'cnp';
        }

        if ($gateway) {
            // should contain accountId, gatewayId, and familyId...
            $gatewayId = $gateway->getId();
            $sourceId = ($originalFamilyId > 0 ? AppSource::ParentPortal : AppSource::MobileApp);
            $redirectUrl = $_SERVER['HTTP_HOST'] . "/parentportal/$accountName/api/v1/gift-certificate/process/$accountId/$gatewayId/$familyId";

            $responseUrl = $_SERVER['HTTP_HOST'] . "/api/open/v1/transactions/spf/$accountId/$locationId/$gatewayId/$staffId/$familyId";
            $orderIdPostBackUrl = null;
            if ($gateway->getProcessorType() == 'paysafe') {
                $orderIdDataArray = [
                    "gatewayId" => $gatewayId,
                    "sourceId" => $sourceId,
                    "paymentType" => $gatewayType,
                    "familyId" => $familyId,
                    "locationId" => $locationId,
                    "remoteAddress" => $_SERVER['REMOTE_ADDR'],
                    "requestAccess" => "ORDER_ID_REQUEST_KEY_20200802",
                ];
                $orderIdPostBackUrl = $_SERVER['HTTP_HOST'] . "/api/open/v1/transactions/spf/new-order-id/$accountId?".http_build_query($orderIdDataArray);
            }

            $response = $gatewayService->getPaymentPageUrlForFamily($accountId, $familyId, $sourceId, $amount, $redirectUrl, $responseUrl, false, $askToStoreCard, false, $requireCvv, $gatewayType, 600, $locationId, $orderIdPostBackUrl, null, null, null, $token);

            if (!$response->hasErrors()) {
                $data = [
                    'url' => $response->getData('url'),
                ];
                return $this->respondWithData($data);
            } else {
                logToPapertrail(print_r($response->getErrors(), true), 'spf-integration-error');
                return $this->respondWithErrors('', $response->getErrors());
            }
        } else {
            logToPapertrail('Unknown Gateway', 'spf-integration-error');
            return $this->respondWithErrors('', ['errors' => ['Unknown Gateway']]);
        }
    }

    public function buyGiftCertificate(Request $request, $accountName)
    {
        require_once(__DIR__ . '/../../../../autoload.php');

        DatabaseService::connectByAccountName($accountName);

        $giftCertificateSettingsRepository = new GiftCertificateSettingsRepository();
        $giftCertificateSettings = $giftCertificateSettingsRepository->get();

        $programId = $giftCertificateSettings->getProgramId();
        $chargeCategoryId = $giftCertificateSettings->getChargeCategoryId();
        $familyId = $request->input('familyId');
        $multiplier = $giftCertificateSettings->getMultiplier();
        $type = $giftCertificateSettings->getType();
        $genericFamilyId = $giftCertificateSettings->getFamilyId();
        if (empty($familyId)) {
            $familyId = $genericFamilyId;
        }

        $useAutoPay = $request->input('useCardOnFile');
        $name = $request->input('name');
        $isGift = ($request->input('gift') === "true" ? true : false);
        $giftName = $request->input('giftName');
        $giftEmail = $request->input('giftEmail');
        $giftMessage = $request->input('giftMessage');
        $email = $request->input('email');
        $phone = $request->input('phone');
        $amount = $request->input('amount');
        $transactionId = $request->input('transactionId');
        $usePaymentCapture = !empty($transactionId);

        if (!$useAutoPay && !$transactionId) {
            return $this->respondWithData([
                "success" => false,
                "errors" => array_values(['No payment type selected']),
            ]);
        }

        if (empty($amount) && !($amount > 0)) {
            return $this->respondWithData([
                "success" => false,
                "errors" => array_values(['Amount must be greater than 0']),
            ]);
        }

        $familyObject = null;
        if ($familyId > 0) {
            $familyObject = $this->familiesRepository->load($familyId);
        }

        if (!$familyObject) {
            return $this->respondWithData([
                "success" => false,
                "errors" => array_values(['Not a valid family']),
            ]);
        }

        $baseTitle = "$name - $email ". ((!empty($phone) && $phone == "Not On File") ? "" : "- $phone");
        $creditTitle = $baseTitle;
        if ($isGift) {
            if (empty($giftName)) {
                $giftName = $name;
            }
            $creditTitle = $giftName . " - " . (!empty($giftEmail) ? $giftEmail : '');
        }

        $creditFamilyId = $familyObject->getId();

        if ($isGift) {
            $creditFamilyId = $genericFamilyId;
        }

        $success = true;
        $errors = [];
        $canProcessPayment = false;
        $locationId = UserLocationsService::current();
        $locationObject = $this->locationRepository->load($locationId);
        $defaultGateway = $this->gatewayRepository->getCardNotPresentGateway();
        $paymentProcessorService = new PaymentProcessorService();
        $orderId = null;
        $currentGatewayId = (($defaultGateway) ? $defaultGateway->getId() : null);
        $paymentAmount = 0;
        $creditAmount = 0;
        $accountsRepository = new AccountsRepository();
        $account = $accountsRepository->getByAccountName($accountName);
        $currencySymbolsRepository = new CurrencySymbolsRepository();
        $currencySymbolObject = $currencySymbolsRepository->load($account->getCurrencySymbolId());
        if ($currencySymbolObject) {
            $currencySymbol = $currencySymbolObject->getSymbol();
        } else {
            $currencySymbol = '';
        }
        $validAutoPayInfo = false;
        $autoPayTransactionInformation = [];
        $chargeEntryObject = null;
        $paymentLineItemObject = null;
        $creditLineItemObject = null;


        if (($usePaymentCapture || $useAutoPay || (float)$paymentAmount == (float)0) && $locationObject instanceof LocationObject && $locationObject->getId() > 0) {
            $canProcessPayment = true;

            if ($usePaymentCapture && !empty($currentGatewayId)) {
                $gatewayTransactionDto = new GatewayTransactionDto();
                $gatewayTransactionDto->setGatewayId($currentGatewayId);
                $gatewayTransactionDto->setPaymentType($paymentProcessorService::payment_type_creditcard_not_present);
                $paymentInformationProcessorDto = $paymentProcessorService->getPaymentDetails($gatewayTransactionDto, $transactionId);
                $orderId = $paymentInformationProcessorDto->getOrderId();

                if ($paymentInformationProcessorDto->getSuccess() &&
                    $paymentInformationProcessorDto->isAuth() &&
                    !$paymentInformationProcessorDto->isCaptured() &&
                    $paymentInformationProcessorDto->getAmount() > 0
                ) {
                    $paymentAmount = $paymentInformationProcessorDto->getAmount();
                    $canProcessPayment = true;
                } else {
                    $success = false;
                    $canProcessPayment = false;
                    $errors[] = "Issue Processing Payment";
                }
            }

            if ($useAutoPay && $amount > 0) {
                $autoPayTransactionInformation = [
                    'UID' => $familyId,
                    'familyid' => $familyId,
                    'email' => $familyObject->getPrimaryEmail(),
                    'autopayenabled' => $familyObject->getAutopayEnabled(),
                    'autopayid' => $familyObject->getCustomerId(),
                    'autopayprofile' => $familyObject->getProfileId(),
                    'lastfour' => $familyObject->getLastFour(),
                    'cardtype' => $familyObject->getIssuer(),
                    'autopaypaymenttype' => $familyObject->getMethod(),
                    'cardexpiremonth' => $familyObject->getExpMonth(),
                    'cardexpireyear' => $familyObject->getExpYear(),
                    'gatewayID' => $familyObject->getGateway(),
                    'amount' => (float)$amount,
                    'orderdescription' => '',
                ];

                if (!empty($autoPayTransactionInformation['autopayid']) || !empty($autoPayTransactionInformation['autopayprofile'])) {
                    $validAutoPayInfo = true;
                    $canProcessPayment = true;
                    $paymentAmount = $amount;
                }
            }
        }


        if ($amount == $paymentAmount && $canProcessPayment) {
            DB::beginTransaction();
            $needsRollBack = false;
            $paymentLineItemObject = null;

            $paymentEntryObject = $this->paymentEntriesRepository->insert(
                PaymentEntryObject::create()
                    ->setFamilyId($familyObject->getId())
                    ->setLocationId($locationObject->getId())
                    ->setFromPortal(true)
                    ->setTitle("GiftCard: " . $baseTitle)
                    ->setDescription("DATETIME STAMP: " . (date("r")) . "\nIP: " . $_SERVER['REMOTE_ADDR'])
                    ->setActiveDate(Carbon::today())
                    ->setDeletedAt(null)
                    ->setIsVisible(1)
                    ->setCreatedAt(Carbon::now())
                    ->setUpdatedAt(null)
                    ->setIsFinalized(false)
                    ->setCreatedSourceToParentPortal()
                    ->setOrderId($orderId)
            );

            if (!($paymentEntryObject instanceof PaymentEntryObject)) {
                $errors[] = "Error saving payment entry";
                $needsRollBack = true;
                $success = false;
            } else {
                $paymentLineItemObject = $this->paymentLineItemsRepository->insert(
                    PaymentLineItemObject::create()
                        ->setEntryId($paymentEntryObject->getId())
                        ->setAmount($paymentAmount)
                        ->setFromPortal(true)
                        ->setAutoPay(false)
                        ->setReferenceNumber('')
                        ->setFeeType('CC_MERCHANT')
                        ->setTitle($paymentEntryObject->getTitle())
                        ->setDescription('')
                        ->setActiveDate(Carbon::today())
                        ->setDeletedAt(null)
                        ->setIsVisible(1)
                        ->setCreatedAt(Carbon::now())
                        ->setUpdatedAt(null)
                        ->setIsFinalized(false)
                );

                if (!($paymentLineItemObject instanceof PaymentLineItemObject)) {
                    $errors[] = "Error saving payment line item";
                    $needsRollBack = true;
                    $success = false;
                } else {
                    $auditMessage = "Ledger: App - Created " . $paymentLineItemObject->getPaymentType();
                    $auditMessage .= " Payment (" . $paymentLineItemObject->getEntryId() . ") " . $currencySymbol . number_format($paymentLineItemObject->getAmount(), 2);
                    $auditMessage .=" (" . $familyObject->getName() . " (". $familyObject->getId() . ")) " . $paymentLineItemObject->getTitle();
                    $this->auditLogRepository->insert($auditMessage);
                }
            }

            if (!$needsRollBack) {
                $chargeEntryObject = $this->chargeEntriesRepository->insert(
                    ChargeEntryObject::create()
                        ->setFamilyId($familyId)
                        ->setLocationId($locationId)
                        ->setFromPortal(true)
                        ->setTitle("GiftCard: " . $baseTitle)
                        ->setIsVisible('1')
                        ->setActiveDate(Carbon::today())
                        ->setDueDate(Carbon::today())
                        ->setEarlyBirdDate(null)
                        ->setCreatedAt(Carbon::now())
                        ->setUpdatedAt(null)
                        ->setIsFinalized(false)
                        ->setCreatedSourceToParentPortal()
                );

                if (!$chargeEntryObject instanceof ChargeEntryObject) {
                    $errors[] = "Error saving charge entry";
                    $needsRollBack = true;
                    $success = false;
                } else {
                    $program = $this->programsRepository->load($programId);
                    $chargeCategory = $this->chargeCategoriesRepository->load($chargeCategoryId);
                    $chargeLineItemObject = $this->chargeLineItemsRepository->insert(
                        ChargeLineItemObject::create()
                            ->setEntryId($chargeEntryObject->getId())
                            ->setAmount($paymentAmount)
                            ->setFeeType("OTHER")
                            ->setFromPortal(true)
                            ->setTaxable(true)
                            ->setTitle($chargeEntryObject->getTitle())
                            ->setIsVisible(true)
                            ->setActiveDate(Carbon::today())
                            ->setDueDate(Carbon::today())
                            ->setCreatedAt(Carbon::now())
                            ->setUpdatedAt(null)
                            ->setIsFinalized(false)
                            ->setProgramName(($program) ? $program->getName() : null)
                            ->setProgramId($programId)
                            ->setChargeCategoryName(($chargeCategory) ? $chargeCategory->getName() : null)
                            ->setChargeCategoryId($chargeCategoryId)
                            ->setAddOn(false)
                    );

                    if (!$chargeLineItemObject instanceof ChargeLineItemObject) {
                        $errors[] = "Error saving payment line item";
                        $needsRollBack = true;
                        $success = false;
                    } else {
                        $chargeEntryObject = $this->chargeEntriesRepository->getChargeEntry($chargeEntryObject->getFamilyId(), $chargeEntryObject->getId());
                        $auditLogMessage = "Ledger: Created Charge (" . $chargeEntryObject->getId() . ") " . $currencySymbol . number_format($chargeEntryObject->getAmount(), 2);
                        $auditLogMessage .= " '{$chargeEntryObject->getTitle()}' ";
                        $auditLogMessage .= " (" . $familyObject->getName()  . " (". $familyObject->getId() . ")) " . $chargeEntryObject->getTitle();
                        $this->auditLogRepository->insert($auditLogMessage);
                    }
                }
            }

            if (!$needsRollBack && !empty($paymentEntryObject) && !empty($chargeEntryObject)) {
                $this->paymentApplyService->applyChargeEntry(
                    $familyId,
                    $paymentEntryObject->getId(),
                    $chargeEntryObject->getId(),
                    $paymentAmount,
                    false,
                    false,
                    true,
                    true
                );
            }

            if (!$needsRollBack) {
                $creditEntryObject = $this->paymentEntriesRepository->insert(
                    PaymentEntryObject::create()
                        ->setFamilyId($creditFamilyId)
                        ->setLocationId($locationObject->getId())
                        ->setFromPortal(true)
                        ->setTitle($creditTitle . ' - (' . $paymentLineItemObject->getEntryId() . ')')
                        ->setDescription("DATETIME STAMP: " . (date("r")) . "\nIP: " . $_SERVER['REMOTE_ADDR'])
                        ->setActiveDate(Carbon::today())
                        ->setDeletedAt(null)
                        ->setIsVisible(1)
                        ->setCreatedAt(Carbon::now())
                        ->setUpdatedAt(null)
                        ->setIsFinalized(false)
                        ->setCreatedSourceToParentPortal()
                        ->setOrderId($orderId)
                );

                if (!($creditEntryObject instanceof PaymentEntryObject)) {
                    $errors[] = "Error saving credit entry";
                    $needsRollBack = true;
                    $success = false;
                } else {
                    // 0 = Any / 1 = Fixed
                    if ($type === "0") {
                        if (!empty($multiplier) && $multiplier > 0) {
                            $creditAmount = $paymentAmount + ($paymentAmount * ($multiplier * 0.01));
                        } else {
                            $creditAmount = $paymentAmount;
                        }
                    } else {
                        $creditAmount = $giftCertificateSettings->getCreditAmount();
                    }

                    $creditLineItemObject = $this->creditLineItemsRepository->insert(
                        CreditLineItemObject::create()
                            ->setEntryId($creditEntryObject->getId())
                            ->setAmount($creditAmount)
                            ->setFromPortal('')
                            ->setReferenceNumber('')
                            ->setFeeType('GIFTCARD')
                            ->setTitle($creditEntryObject->getTitle())
                            ->setDescription($creditEntryObject->getDescription())
                            ->setActiveDate($creditEntryObject->getActiveDate())
                            ->setDeletedAt(null)
                            ->setIsVisible(1)
                            ->setCreatedAt(Carbon::now())
                            ->setUpdatedAt(null)
                            ->setIsFinalized(false)
                    );

                    if (!($creditLineItemObject instanceof CreditLineItemObject)) {
                        $errors[] = "Error saving payment line item";
                        $needsRollBack = true;
                        $success = false;
                    } else {
                        $auditMessage = "Ledger: App - Created " . $creditLineItemObject->getCreditType();
                        $auditMessage .= " Credit (" . $creditLineItemObject->getEntryId() . ") " . $currencySymbol . number_format($creditLineItemObject->getAmount(), 2);
                        $auditMessage .= " (" . $familyObject->getName()  . " (". $familyObject->getId() . ")) " . $creditLineItemObject->getTitle();
                        $this->auditLogRepository->insert($auditMessage);
                    }
                }
            }

            if (!$needsRollBack) {
                $paymentProcessed = false;
                $authId = null;
                $gatewayId = null;
                $cardType = null;
                $lastFour = null;

                if ($usePaymentCapture) {
                    $transactionInformation = [
                        'UID' => $familyId,
                        'paymenttype' => $paymentProcessorService::payment_type_creditcard_not_present,
                        'familyid' => $familyId,
                        'email' => $familyObject->getPrimaryEmail(),
                        'transactionid' => $transactionId,
                        'amount' => (float)$paymentAmount,
                        'orderId' => $orderId,
                        'orderdescription' => '',
                    ];

                    try {
                        $paymentProcessorDto = $paymentProcessorService->capturePayment($transactionInformation);
                        $paymentProcessorDto->doTransaction();

                        if ($paymentProcessorDto->getSuccess()) {
                            $lastFour = (empty($request->get('cardLastFour')) ? null : $request->get('cardLastFour'));
                            $cardType = (empty($request->get('cardType')) ? null : $request->get('cardType'));
                            $authId = $paymentProcessorDto->getAuthCode();
                            $gatewayId = $paymentProcessorDto->getGatewayId();
                            $transactionId = $paymentProcessorDto->getTransactionId();

                            $paymentProcessed = true;
                        } else {
                            $transactionInfo = new GatewayTransactionDto();
                            $transactionInfo->setGatewayId($currentGatewayId);
                            $transactionInfo->setTransactionId($transactionId);
                            $transactionInfo->setAmount($paymentAmount);

                            try {
                                $paymentProcessorService->voidPayment($transactionInfo);
                            } catch (\Exception $e) {
                            }

                            $errors = array_merge($errors, $paymentProcessorDto->getErrors());
                            $success = false;
                            $paymentProcessed = false;
                        }
                    } catch (\Exception $e) {
                        $transactionInfo = new GatewayTransactionDto();
                        $transactionInfo->setGatewayId($currentGatewayId);
                        $transactionInfo->setTransactionId($transactionId);
                        $transactionInfo->setAmount($paymentAmount);

                        try {
                            $paymentProcessorService->voidPayment($transactionInfo);
                        } catch (\Exception $e) {
                        }

                        $errors = $errors[] = 'Unknown error, processing payment';
                        $success = false;
                        $paymentProcessed = false;
                    }
                }

                if ($useAutoPay && !$usePaymentCapture) {
                    if ($validAutoPayInfo) {
                        try {
                            $paymentProcessor = $paymentProcessorService->createPayment($autoPayTransactionInformation);
                            $paymentProcessor->doTransaction();

                            if ((bool)$paymentProcessor->getSuccess()) {
                                $authId = $paymentProcessor->getAuthCode();
                                $gatewayId = $paymentProcessor->getGatewayId();
                                $cardType = $paymentProcessor->getCardType();
                                $lastFour = $paymentProcessor->getLastFour();
                                $transactionId = $paymentProcessor->getTransactionId();
                                $paymentProcessed = true;
                            } else {
                                $success = false;
                                $errors = array_merge($errors, $paymentProcessor->getErrors());
                            }
                        } catch (\Exception $e) {
                            $success = false;
                            $errors[] = 'Error processing card on file';
                        }
                    }
                }

                if ($paymentProcessed) {
                    if ($paymentLineItemObject instanceof PaymentLineItemObject) {
                        $paymentLineItemObject->setLastFour($lastFour);
                        $paymentLineItemObject->setCardType($cardType);
                        $paymentLineItemObject->setAuth($authId);
                        $paymentLineItemObject->setGatewayId($gatewayId);
                        $paymentLineItemObject->setTid($transactionId);
                        $this->paymentLineItemsRepository->update($paymentLineItemObject);
                    }
                } else {
                    $errors[] = "Error processing payment";
                    $needsRollBack = true;
                }
            }

            if ($needsRollBack) {
                DB::rollBack();
            } else {
                DB::commit();
            }

            if ($success === true) {
                $paymentEntryObject = $this->paymentEntriesRepository->getPaymentEntry($paymentEntryObject->getId());

                if ($giftCertificateSettings->getImage() !== '') {
                    $giftImage = '<img src="https://app.iclasspro.com/api/v1/img/' . $giftCertificateSettings->getImage() . '" alt="thank you image"';
                } else {
                    $giftImage = '<img src="https://app.iclasspro.com/images/gift-card.jpg"';
                }

                $credit = $currencySymbol . number_format($creditAmount, 2);
                $giftVariables = [
                    'gift_title' => $giftCertificateSettings->getTitle(),
                    'gift_amount' => $credit,
                    'gift_message' => (!empty($giftMessage) ? '---<br/>' . $giftMessage . '<br/>---' : ''),
                    'gift_email' => ($isGift ? $giftEmail : $email),
                    'gift_name' => ($isGift ? $giftName : $name),
                    'gift_purchaser' => $name,
                    'gift_image' => $giftImage,
                    'gift_purchaser_email' => $email,
                ];

                // Online activity
                $requestType = 'PAYMENT';
                $onlineActivityObj = OnlineActivityObject::create($familyId, $requestType);
                $onlineActivityObj->setPaymentId($paymentLineItemObject->getEntryId());
                $onlineActivityObj->setAmount($paymentLineItemObject->getAmount());
                $onlineActivityObj->setLocationId($locationId);
                $this->onlineActivitiesRepository->insert($onlineActivityObj);

                $requestTypeGift = 'GIFTCARD';
                $onlineActivityObjGift = OnlineActivityObject::create($familyId, $requestTypeGift);
                $onlineActivityObjGift->setLedgerEntryPaymentId($creditLineItemObject->getEntryId());
                $onlineActivityObjGift->setAmount($creditLineItemObject->getAmount());
                $onlineActivityObjGift->setLocationId($locationId);
                $this->onlineActivitiesRepository->insert($onlineActivityObjGift);

                if (!self::sendGiftReceipt($familyId, $locationId, $paymentEntryObject, [$chargeEntryObject], $name, $email, $phone, $creditAmount, $giftVariables, $account->getId())) {
                    $this->auditLogRepository->insert("Error sending gift certificate email (Amount: {$creditLineItemObject->getAmount()} : $name : $email)");
                }
                if ($isGift && !empty($giftEmail)) {
                    self::sendGift($locationId, $giftVariables, $account->getId());
                }
            }

            return $this->respondWithData([
                "success" => $success,
                "errors" => $errors,
            ]);
        } elseif ($canProcessPayment) {
            return $this->respondWithData(
                [
                    "success" => false,
                    "errors" => $errors,
                ]
            );
        } else {
            return $this->respondWithData(
                [
                    "success" => false,
                    "errors" => array_values(['Requested amount does not equal gateway']),
                ]
            );
        }
    }

    /**
     * @param int $familyId
     * @param int $locationId
     * @param PaymentEntryObject $paymentEntryObject
     * @param ChargeEntryObject[] $charges
     * @param string $recipient
     * @param string $email
     * @param string $phone
     * @param string $credit
     * @param array $giftVariables
     * @returns void
     * @return bool
     */
    public static function sendGiftReceipt($familyId, $locationId, $paymentEntryObject, $charges, $recipient, $email, $phone, $credit, $giftVariables, $accountId)
    {
        try {
            $generalVariables = EmailBlastService::getGeneralVariables($locationId, $accountId);
            $transactionVariables = EmailBlastService::getReceiptEmailVariables($charges, $paymentEntryObject, $familyId);

            $giftCertificateSettingsRepository = new GiftCertificateSettingsRepository();
            $settings = $giftCertificateSettingsRepository->get();

            $transactionVariables['type'] = $settings->getTitle();
            $transactionVariables['recipient'] = $recipient;
            $transactionVariables['email'] = $email;
            $transactionVariables['phone'] = $phone;
            $transactionVariables['credit'] = $credit;

            // merge gift variables in
            $transactionVariables = array_merge($transactionVariables, $giftVariables, $generalVariables);

            $familyCommunicationService = new FamilyCommunicationService();
            $familyCommunicationService->sendEmail(
                $familyId,
                'pp_gift_receipt',
                $transactionVariables,
                false,
                'gift-receipt.pdf',
                $locationId
            );
        } catch (\Exception $e) {
            return false;
        }
        return true;
    }

    /**
     * @param int $locationId
     * @param array $giftVariables
     * @param int $accountId
     * @returns void
     * @throws \Exception

     */
    public static function sendGift($locationId, $giftVariables, $accountId)
    {
        $generalVariables = EmailBlastService::getGeneralVariables($locationId, $accountId);
        $giftCertificateSettingsRepository = new GiftCertificateSettingsRepository();
        $settings = $giftCertificateSettingsRepository->get();

        // used to set email address to send to
        $giftVariables['email'] = $giftVariables['gift_email'];

        // Force gift to use generic family
        $familyId = $settings->getFamilyId();

        $emailVariables = array_merge($giftVariables, $generalVariables);

        $familyCommunicationService = new FamilyCommunicationService();
        $familyCommunicationService->sendEmail(
            $familyId,
            'pp_you_have_a_gift',
            $emailVariables,
            false,
            null,
            $locationId
        );
    }
}
