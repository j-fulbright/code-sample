import { ModalViewObject } from 'src/app/@shared/objects/modal.view-object';
import { AccountStoreService } from './../../services/account-store.service';
import { LOCAL_API, USE_LOCAL_API } from '../../environments/environment';
import { FormGroup, FormControl, Validators } from '@angular/forms';
import { Location, DecimalPipe } from '@angular/common';
import { ImagesService } from './../../services/images.service';
import { TitleService } from './../../services/title.service';
import { OrganizationStoreService } from './../../services/organization-store.service';
import { Router, ActivatedRoute } from '@angular/router';
import { Component, OnInit } from '@angular/core';
import { LocationStoreService } from '../../services/location-store.service';
import { LocationViewObject } from 'src/app/@shared/objects/location.view-object';
import { OrganizationObject } from 'src/app/@shared/objects/organization.object';
import { LocationObject } from 'src/app/@shared/objects/location.object';
import {
    GiftCertificateObject,
    GiftCertificateService,
} from 'src/services/gift-certificate.service';
import { AccountObject } from 'src/app/@shared/objects/account.object';
import { AccountService } from 'src/services/account.service';
import { PaymentFormSettingsObject } from 'src/app/@shared/objects/payment-form-settings.object';
import { StorageService } from 'src/services/storage.service';
import { PolicyObject } from 'src/app/@shared/objects/policy.object';
import { PoliciesCheckService } from 'src/services/policies-check.service';
import { HtmlSanitizerService } from 'src/services/html-sanitizer.service';
import { CreditCardValidationService } from 'src/services/credit-card-validation.service';

@Component({
    selector: 'customer-portal-gift-certificate',
    templateUrl: './gift-certificate.component.html',
    styleUrls: ['./gift-certificate.component.scss'],
})
export class GiftCertificateComponent implements OnInit {
    modalText: string;
    showModal: boolean;
    closeModalAction: Function;

    giftForm: FormGroup;
    giftCertificateHeader: string;
    org: OrganizationObject;
    locationObj: LocationObject;
    dataLoaded: boolean;
    isProcessing: boolean;
    hideFamilyInfo: boolean;
    settings: any;
    imagesService = ImagesService;
    modal: ModalViewObject;
    purchased: boolean;
    credit: number;
    giftCertificate: GiftCertificateObject;
    private orgCode: string;
    private account: AccountObject;
    validAutoPayPaymentType = false;
    viewingDisclaimer: boolean;
    locationObject: LocationViewObject;
    oneTimePaymentDisclosure: PolicyObject;
    guestCheckoutKey: string;
    creditCardValidationService = CreditCardValidationService;

    constructor(
        private router: Router,
        private route: ActivatedRoute,
        private location: Location,
        private organizationStoreService: OrganizationStoreService,
        private accountStoreService: AccountStoreService,
        private decimalPipe: DecimalPipe,
        private giftCertificateService: GiftCertificateService,
        private locationStoreService: LocationStoreService,
        private accountService: AccountService,
        private policiesCheckService: PoliciesCheckService,
        private htmlSanitizerService: HtmlSanitizerService
    ) {
        console.log('Hello GiftCertificateComponent Component');
    }

    ngOnInit() {
        this.route.params.subscribe((params: { orgCode: string }) => {
            this.orgCode = params.orgCode;

            this._loadOneTimePaymentDisclosure();
            this._loadData();
        });
        CreditCardValidationService.resetValidation();
    }

    updateCredit(amount: string) {
        const amountControl = this.giftForm.get('amount');
        const creditControl = this.giftForm.get('credit');

        if (amountControl.invalid) {
            creditControl.patchValue('0.00');
            return;
        }

        if (amount === '') {
            creditControl.patchValue('0.00');
        } else if (this.settings.multiplier === '0') {
            creditControl.patchValue(this._getFloat(amount));
        } else {
            const credit =
                parseFloat(amount) +
                (parseFloat(amount) * this.settings.multiplier) / 100;
            creditControl.patchValue(this._getFloat(credit));
        }
    }

    purchaseGiftCertificate() {
        this.viewingDisclaimer = true;
    }

    processGiftCertificate() {
        const giftCertificate = new GiftCertificateObject();

        giftCertificate.amount = this.giftForm.get('amount').value;
        giftCertificate.name = this.giftForm.get('name').value;
        giftCertificate.email = this.giftForm.get('email').value;
        giftCertificate.phone = this.giftForm.get('phone').value;
        giftCertificate.giftName = this.giftForm.get('giftName').value;
        giftCertificate.giftEmail = this.giftForm.get('giftEmail').value;
        giftCertificate.giftMessage = this.giftForm.get('giftMessage').value;
        giftCertificate.gift = this.giftForm.get('isGift').value;
        giftCertificate.useCardOnFile = this.giftForm.get('useAutopay').value;
        giftCertificate.familyId = String(this.account ? this.account.id : 0);
        StorageService.set('giftCertificate', JSON.stringify(giftCertificate));
        StorageService.set('gcCredit', this.giftForm.get('credit').value);

        const useAutopay = this.giftForm.get('useAutopay').value;
        if (useAutopay) {
            this.payNow(null);
            return;
        }

        const paymentFormSettings = new PaymentFormSettingsObject();
        const account = this.accountStoreService.getCurrentAccount();
        paymentFormSettings.familyId = account ? account.id : 0;

        if (this.giftForm.get('amount').value) {
            paymentFormSettings.amount = this.giftForm.get('amount').value;
        }

        paymentFormSettings.locationId = this.locationStoreService.getCurrentLocationId();
        paymentFormSettings['giftCertificate'] = giftCertificate;
        paymentFormSettings['origin'] = 'portal-gift-certificate';

        if (!window.location.href.includes('portal')) {
            paymentFormSettings['prefix'] = this.orgCode;
        } else {
            paymentFormSettings['prefix'] = 'portal/' + this.orgCode;
        }

        paymentFormSettings['serverRedirect'] = window.location.origin;

        const paymentUrl = this.giftCertificateService.useLocalApi(USE_LOCAL_API, LOCAL_API).getSpfUrl(this.org.code, paymentFormSettings);
        paymentUrl.subscribe(response => {
            window.location.href = response.data.url;
        });
    }

    payNow(transactionId?: string) {
        if (this.isProcessing) {
            return;
        }

        this.isProcessing = true;
        $('.modal-processing').modal('show');

        this.giftCertificate = JSON.parse(StorageService.get('giftCertificate')) as GiftCertificateObject;
        StorageService.remove('giftCertificate');
        this.credit = this._parseNumberForSPF(StorageService.get('gcCredit'));
        this.giftCertificate.amount = this._parseNumberForSPF(String(this.giftCertificate.amount));

        if (transactionId) {
            console.log('[🔁][API] processing SPF transaction');
            this.giftCertificate.transactionId = transactionId;
        }

        this.giftCertificate.locationId = this.locationStoreService.getCurrentLocationId();

        this.giftCertificateService
            .useLocalApi(USE_LOCAL_API, LOCAL_API)
            .buy(this.org.code, this.giftCertificate)
            .subscribe(
                (response: any) => {
                    console.log(response);
                    if (!response.hasErrors) {
                        $('.modal-processing').modal('hide');
                        this.isProcessing = false;
                        if (response.data && response.data.success) {
                            $('.modal-thank-you').modal('show');
                            this.purchased = true;
                        } else {
                            this._showToast('Unable to complete purchase', function () {
                                this.dataLoaded = false;
                                this._loadData();
                                this.viewingDisclaimer = false;
                            });
                        }
                    } else {
                        this.isProcessing = false;
                        $('.modal-processing').modal('hide');
                        console.error(response.errorMessage);
                        this._showToast(response.errorMessage);
                    }
                },
                reason => {
                    this.isProcessing = false;
                    $('.modal-processing').modal('hide');
                    console.error(reason);
                    this._showToast('Unable to complete purchase');
                    return;
                }
            );
    }

    getLogo() {
        if (!this.settings.image || this.settings.image.length === 0) {
            return ImagesService.getImageUrl(
                'assets/customer-portal/images/gift-card-header.png'
            );
        }
        return ImagesService.getImageUrl(this.settings.image);
    }

    getThankYouLogo() {
        return ImagesService.getImageUrl(
            'assets/customer-portal/images/thank-you-header.png'
        );
    }

    goBack(returnTo?: boolean) {
        if (returnTo) {
            this.router.navigate(['/' + this.orgCode + '/dashboard']);
        } else if (window.history.length > 2 && !this.purchased) {
            this.location.back();
        } else {
            $('.modal-thank-you').modal('hide');
        }
    }

    getTextHtml(text: string) {
        return this.htmlSanitizerService.sanitize(text);
    }

    closeModal() {
        if (this.closeModalAction) {
            this.closeModalAction();
            this.closeModalAction = null;
        }
    }

    buyNowIsDisabled() {
        const isGift = this.giftForm.get('isGift').value as boolean;
        const senderName = this.giftForm.get('name') as FormControl;
        const senderEmail = this.giftForm.get('email') as FormControl;
        const senderPhone = this.giftForm.get('phone') as FormControl;

        if (this.isProcessing) {
            return true;
        }

        if ((senderEmail.value || '').trim().length === 0) {
            // If email fails, check phone
            if ((senderPhone.value || '').trim().length === 0) {
                return true;
            }
        } else if ((senderPhone.value || '').trim().length === 0) {
            // If phone fails, check email
            if ((senderEmail.value || '').trim().length === 0) {
                return true;
            }
        }

        if (this.hideFamilyInfo === false && isGift === null) {
            if (senderName.status === 'VALID') {
                return false;
            }
        }

        if (isGift && this.giftForm.invalid && this.giftForm.dirty) {
            return true;
        }

        const useAutopay = this.giftForm.get('useAutopay').value as boolean;
        const accepted = this.giftForm.get('accepted').value as boolean;
        if (this.settings.hasAutopay && useAutopay && !accepted) {
            return true;
        }
        return false;
    }

    noWhitespaceValidator(control: FormControl) {
        const isWhitespace = (control.value || '').trim().length === 0;
        const isValid = !isWhitespace;
        return isValid ? null : { 'whitespace': true };
    }

    private _setupAmountFields() {
        // Any Amount
        if (this.settings.type === '0') {
            this.giftForm.addControl(
                'amount',
                new FormControl(this.settings.minAmount, [
                    Validators.required,
                    Validators.min(this.settings.minAmount),
                    Validators.max(this.settings.maxAmount)
                ])
            );
            this.giftForm.addControl(
                'credit',
                new FormControl({ value: '', disabled: true })
            );
            this.updateCredit(this.giftForm.get('amount').value);
        } else {
            this.giftForm.addControl(
                'amount',
                new FormControl({
                    value: this._getFloat(this.settings.chargeAmount),
                    disabled: true
                })
            );
            this.giftForm.addControl(
                'credit',
                new FormControl({
                    value: this._getFloat(this.settings.creditAmount),
                    disabled: true
                })
            );
        }
    }

    private _loadOneTimePaymentDisclosure() {
        this.guestCheckoutKey = StorageService.get('guestCheckoutKey');
        this.policiesCheckService.getOneTimePaymentDisclosure(this.guestCheckoutKey, this.orgCode).then(otp => {
            if (otp) {
                this.oneTimePaymentDisclosure = otp.oneTimePaymentDisclosure;
            }
        });
    }

    private _loadData() {
        this.org = this.organizationStoreService.getCurrentOrganization();
        this.account = this.accountStoreService.getCurrentAccount();

        if (this.org.hasLocations) {
            this.locationObject = this.locationStoreService.getCurrentLocation();
        }

        const transactionId = StorageService.get('transactionId') as string;
        const cardLastFour = StorageService.get('cardLastFour') as string;
        const cardType = StorageService.get('cardType') as string;
        StorageService.remove('transactionId');
        StorageService.remove('cardLastFour');
        StorageService.remove('cardType');
        console.log('[✅] transactionId found: ' + transactionId);
        console.log('[✅] cardLastFour found: ' + cardLastFour);
        console.log('[✅] cardType found: ' + cardType);

        const loggedIn = this.account ? this.account.id : 0;

        this.purchased = false;
        this.giftCertificateService
            .useLocalApi(USE_LOCAL_API, LOCAL_API)
            .getSettings(this.orgCode, this.account ? this.account.id : 0)
            .subscribe(response => {
                if (!response.hasErrors) {
                    this.settings = response.data;

                    if (!this.settings.enabledNotLoggedIn && !loggedIn) {
                        console.log('Not enabled');
                        this.router.navigate([
                            '/' + this.orgCode + '/dashboard'
                        ]);
                    }

                    this.dataLoaded = true;
                    this.isProcessing = false;
                    TitleService.setTitle(
                        this.org.giftTitle
                            ? this.org.giftTitle
                            : 'Gift Certificates'
                    );
                    this._buildForm();
                    this._setupAmountFields();
                    this._setupFamilyData();

                    if (transactionId) {
                        this.payNow(transactionId);
                    }

                    this.route.queryParams.subscribe((queryParams: { data: any }) => {
                        if (queryParams.data !== undefined) {
                            const returnData = JSON.parse(queryParams.data);
                            this.giftCertificate = JSON.parse(StorageService.get('giftCertificate')) as GiftCertificateObject;

                            let prefix = '';
                            if (window.location.href.includes('portal')) {
                                prefix = 'portal/';
                            }
                            this.credit = this._parseNumberForSPF(StorageService.get('gcCredit'));

                            if (window.history.pushState) {
                                const newurl = window.location.protocol + '//' + window.location.host + '/' + prefix + this.org.code + '/gift-certificate';
                                window.history.pushState({ path: newurl }, document.getElementsByTagName('title')[0].innerHTML, newurl);
                            }
                            if (returnData.data.success === true) {
                                if (returnData.errors.length === 0) {
                                    this.purchased = true;
                                    $('.modal-processing').modal('hide');
                                    $('.modal-thank-you').modal('show');
                                    this.isProcessing = false;
                                    console.log(this.purchased);
                                    console.log(this.giftCertificate);
                                } else {
                                    let errors = '';
                                    if (returnData.errors['']) {
                                        returnData.errors[''].forEach(function (value) {
                                            errors += value + ' ';
                                        });
                                    } else {
                                        returnData.errors.forEach(function (value) {
                                            errors += value + ' ';
                                        });
                                    }
                                    this.isProcessing = false;
                                    $('.modal-processing').modal('hide');
                                    this._showToast(errors);
                                }
                            } else {
                                this.isProcessing = false;
                                $('.modal-processing').modal('hide');
                                this._showToast('Unable to complete purchase');
                                return;
                            }
                        }
                    });

                }
            });

        if (loggedIn) {
            this.accountService
                .useLocalApi(USE_LOCAL_API, LOCAL_API)
                .getSummary()
                .subscribe(response => {
                    if (!response.hasErrors) {
                        const accountSummary = response.data;
                        this.validAutoPayPaymentType = accountSummary.paymentMethod === 'cnp';
                    }
                });
        }
    }

    private _setupFamilyData() {
        if (this.account && this.account.id > 0) {
            this.giftForm.get('name').patchValue(this.settings.name);
            this.giftForm.get('email').patchValue(this.settings.email);
            this.giftForm.get('phone').patchValue(this.settings.phone);
            this.hideFamilyInfo = true;
        } else {
            this.hideFamilyInfo = false;
        }
        this.giftForm.updateValueAndValidity();
    }

    private _showToast(text: string, onClose?: Function) {
        this.modalText = text;
        this.showModal = true;
        if (onClose) {
            this.closeModalAction = onClose;
        }
    }

    private _getFloat(number: string | number) {
        return this.decimalPipe.transform(number, '1.2-2');
    }

    private _parseNumberForSPF(str: string) {
        let strg = str || '';
        let decimal = '.';
        strg = strg.replace(/[^0-9$.,]/g, '');
        if (strg.indexOf(',') > strg.indexOf('.')) {
            decimal = ',';
        }

        if ((strg.match(new RegExp('\\' + decimal, 'g')) || []).length > 1) {
            decimal = '';
        }

        if (
            decimal !== '' &&
            strg.length - strg.indexOf(decimal) - 1 === 3 &&
            strg.indexOf('0' + decimal) !== 0
        ) {
            decimal = '';
        }
        strg = strg.replace(new RegExp('[^0-9$' + decimal + ']', 'g'), '');
        strg = strg.replace(',', '.');
        return parseFloat(strg);
    }

    private _buildForm() {
        this.giftForm = new FormGroup({
            // Buyer
            name: new FormControl('', [Validators.required, this.noWhitespaceValidator]),
            email: new FormControl('', [Validators.email]),
            phone: new FormControl(),

            // Recipient
            giftName: new FormControl('', [Validators.required, this.noWhitespaceValidator]),
            giftEmail: new FormControl('', [Validators.email]),
            giftMessage: new FormControl(),

            // Checkboxes
            isGift: new FormControl(),
            useAutopay: new FormControl(),
            accepted: new FormControl()
        });

        this.giftForm.get('isGift').valueChanges.subscribe(checked => {
            if (checked) {
                this.giftForm.get('giftName').enable();
                this.creditCardValidationService.validate(this.giftForm.value.giftName, 'giftName');
            } else {
                this.giftForm.get('giftName').disable();
                // if unchecking is a gift, and they have autopay
                // re-populate the other fields, as they're required
                if (this.settings.hasAutopay) {
                    this._setupFamilyData();
                }
                this.creditCardValidationService.validate('', 'giftName');
            }
            this.giftForm.updateValueAndValidity();
        });
    }
}
