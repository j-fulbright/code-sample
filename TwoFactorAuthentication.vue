<template>
    <div class="has-min-height-small position-relative" v-if="!dataLoaded">
        <div class="center-wrap">
            <span class="loading-icon" aria-hidden="true"></span>
            <span class="sr-only">Loading...</span>
        </div>
    </div>

    <div v-if="dataLoaded" :class="{ 'has-max-width margin-horizontal-auto': source === 2 }">
        <div class="alert alert-warning" role="alert" v-if="!twoFactorKey">
            Please enter your two factor authentication code. Contact your administrator
            if you have lost access to your authentication code.
        </div>

        <div v-if="twoFactorKey">
            <div class="alert alert-warning" role="alert">
              Your administrator requires two-factor authentication (2FA) to be set up in order to use your account.
              Follow the instructions below to set this up.
            </div>
            <ol class="list-spacing-small">
              <li>
                Install an <a href="-----" class="text-link" target="_blank">authenticator app</a> on your phone.
              </li>
              <li>
                Scan the QR code with your phone's camera app or enter the secret key manually into your authenticator app:
                <div class="display-flex justify-content-around margin-top-small">
                    <div class="text-center">
                      <qrcode-vue :value="twoFactorURL" size="128" level="H" />
                    </div>
                    <div class="hr-underlay-vertical">
                      <span class="hr-underlay-text">or</span>
                    </div>
                    <div class="text-center">
                      <div class="display-flex flex-direction-column justify-content-center height-100">
                        <em>Secret key:</em>
                        <code class="h1 text-weight-normal margin-top-small">{{twoFactorKey}}</code>
                      </div>
                    </div>
                </div>
              </li>
              <li>
                Now enter the 6-digit code from your authenticator app:
              </li>
            </ol>
        </div>
        <div class="form-group login-control code margin-top-small">
          <div class="input-styler">
            <input class="form-control" :class="{ 'has-left-icon ui-large': source === 0 || source === 1, 'input-underline': source === 2 }" type="text" id="code" name="code" placeholder="6-digit code" v-model="code" maxlength="6" v-on:keyup.enter="validateCode">
            <span class="icon-lock" aria-hidden="true"></span>
          </div>
        </div>
        
        <div class="alert alert-danger" role="alert" v-if="invalidCode">
            There was an error validating your code.
        </div>

        <div class="form-group">
            <div class="margin-top-small">
                <!-- type="submit" due to Staff Portal styling, but prevent its onsubmit event -->
                <button type="submit" id="twofactorbutton" v-on:click.prevent="validateCode" class="btn btn-primary ui-large" :disabled="code.length < 6">Submit</button>
            </div>
        </div>
        <div class="margin-top-small">
          <a href="#" id="backToLogin" v-on:click.prevent="backToLogin" class="text-link text-uppercase fg-danger">
            Cancel
          </a>
        </div>
    </div>
</template>

<script>
import { ref, nextTick, onMounted } from "vue";
import axios from 'axios';
import QrcodeVue from 'qrcode.vue'

export default {
    setup() {
        const source = ref(0);
        const code = ref('');
        const twoFactorKey = ref(null);
        const twoFactorURL = ref(null);
        const dataLoaded = ref(false);
        const invalidCode = ref(false);

        let staffPortalUrl = '';

        onMounted(async () => {
            // elements have been created, so the `ref` will return an element.
            // but the elements have not necessarily been inserted into the DOM yet.
            // you can use $nextTick() to wait for that to have happened.
            // this is especially necessary if you want to to get dimensions or position of that element.
            await nextTick();
            const element = document.getElementById('two-factor');
            if (element) {
                source.value = parseInt(element.dataset.source);
            }

            if (source.value > 1) {
                staffPortalUrl = 'staffportal/';
            }

            axios.get('/api/' + staffPortalUrl + 'v1/check-two-factor/' + source.value).then((response) => {
                const otpCode = response.data.data.secret_key;
                const qrcodeURL = response.data.data.qrcode_data;
                dataLoaded.value = true;

                // means we need to setup
                if (otpCode !== '') {
                    twoFactorKey.value = otpCode;
                    twoFactorURL.value = qrcodeURL;
                }
            }, (error) => {
                dataLoaded.value = true;
                console.log(error);
            });
        });

        function validateCode() {
            dataLoaded.value = false;

            const data = {
                source: source.value,
                code: code.value,
                secret: twoFactorKey.value
            };

            axios.post('/api/' + staffPortalUrl + 'v1/validate-two-factor', data).then((response) => {
                const valid = response.data.data.valid;

                if (valid) {
                    location.reload();
                } else {
                    invalidCode.value = true;
                    code.value = '';
                }
                dataLoaded.value = true;
            }, (error) => {
                dataLoaded.value = true;
                invalidCode.value = true;
                console.error(error);
            });
        }

        function backToLogin() {
            axios.get('/api/' + staffPortalUrl + 'v1/invalidate-session/' + source.value).then((response) => {
                    location.reload();
            }, (error) => {
                console.error(error);
                location.reload();
            });
        }

        return {
            twoFactorKey,
            twoFactorURL,
            dataLoaded,

            code,
            source,
            validateCode,
            invalidCode,

            backToLogin
        }
    },
    components: {
        QrcodeVue
    }
}

</script>
