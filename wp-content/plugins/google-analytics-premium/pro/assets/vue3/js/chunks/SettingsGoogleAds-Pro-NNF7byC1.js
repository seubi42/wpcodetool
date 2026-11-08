import { o as openBlock, c as createElementBlock, a as createBaseVNode, t as toDisplayString, u as unref, B as createTextVNode, aK as welcomeImage, p as computed, P as getMonsterInsightsUrl, D as watch, z as onMounted, v as createCommentVNode, w as withDirectives, aA as vShow, aL as vModelRadio, C as withModifiers, F as Fragment, G as createBlock, l as ref, g as getMiGlobal, y as nextTick } from "./AppOverlays-Dpqzx_3P.js";
import { u as useAuth } from "./monsterinsights-Pro-B9tAtBLR.js";
import { m as miAjax } from "./useNotices-CwGRydDs.js";
import "./TheAppHeader-CJeDav-v.js";
import "./sanitize-CE5uy2g6.js";
import "./settings-WrluSu6E.js";
import "./globals-C78yxw47.js";
import "./addons-rxJejcjK.js";
import "./Modal-D1CHxx1Y.js";
import "./license-D2vquWPE.js";
const { __ } = wp.i18n;
const AUTH_FAILED_CODE = 6001;
const fetchAdsSettings = async () => {
  try {
    return await miAjax("monsterinsights_ads_get_settings");
  } catch (error) {
    throw new Error(
      error?.message || __(
        "Failed to fetch Google Ads settings.",
        "google-analytics-for-wordpress"
      )
    );
  }
};
const updateAdsSetting = async (key, value) => {
  return miAjax("monsterinsights_ads_update_setting", { key, value });
};
const checkConversions = async () => {
  return miAjax("monsterinsights_ads_check_conversions");
};
const fetchAccessToken = async () => {
  try {
    const response = await miAjax("monsterinsights_ads_get_token");
    return response?.access_token || null;
  } catch (error) {
    const code = error?.data?.code ?? error?.code;
    if (code === AUTH_FAILED_CODE) {
      const err = new Error("auth_failed");
      err.code = AUTH_FAILED_CODE;
      throw err;
    }
    throw new Error(
      error?.message || __(
        "An unknown error occurred while fetching the access token.",
        "google-analytics-for-wordpress"
      )
    );
  }
};
const api = {
  fetchAdsSettings,
  updateAdsSetting,
  checkConversions,
  fetchAccessToken,
  AUTH_FAILED_CODE
};
const _hoisted_1$1 = { class: "monsterinsights-google-ads-welcome" };
const _hoisted_2$1 = { class: "monsterinsights-google-ads-welcome__content" };
const _hoisted_3$1 = { class: "monsterinsights-google-ads-welcome__list" };
const _hoisted_4$1 = { class: "monsterinsights-google-ads-welcome__offer" };
const _hoisted_5$1 = ["innerHTML"];
const _hoisted_6$1 = { class: "monsterinsights-google-ads-welcome__disclaimer" };
const _hoisted_7$1 = ["innerHTML"];
const _hoisted_8$1 = { class: "monsterinsights-google-ads-welcome__image" };
const _hoisted_9$1 = ["src"];
const _sfc_main$1 = {
  __name: "GoogleAdsGetStarted",
  emits: ["completeOnboarding"],
  setup(__props, { emit: __emit }) {
    const { __: __2, sprintf } = wp.i18n;
    const emit = __emit;
    const strings = computed(() => ({
      disclaimer: sprintf(
        __2(
          "New to Google? Get $500 in ad credits when you spend your first $500 within 60 days* (%sT&Cs apply%s).",
          "google-analytics-for-wordpress"
        ),
        '<a href="' + getMonsterInsightsUrl(
          "google-ads",
          "google-pax",
          "https://www.monsterinsights.com/docs/google-ads-credit-terms-and-conditions/"
        ) + '" target="_blank">',
        "</a>"
      ),
      google_ads_policies: sprintf(
        __2(
          `*By clicking "Get Started" you agree with MonsterInsights' Terms and Conditions and %sGoogle Ads Policies%s.`,
          "google-analytics-premium"
        ),
        '<a href="https://support.google.com/adspolicy/answer/6008942" target="_blank">',
        "</a>"
      )
    }));
    return (_ctx, _cache) => {
      return openBlock(), createElementBlock("div", null, [
        createBaseVNode("div", _hoisted_1$1, [
          createBaseVNode("div", _hoisted_2$1, [
            createBaseVNode("h3", null, toDisplayString(unref(__2)("Drive better results from a single campaign with Google Ads", "google-analytics-premium")), 1),
            createBaseVNode("ul", _hoisted_3$1, [
              createBaseVNode("li", null, [
                _cache[1] || (_cache[1] = createBaseVNode("span", { class: "monsterinsights-google-ads-welcome__check" }, "✔", -1)),
                createBaseVNode("strong", null, toDisplayString(unref(__2)("With a Performance Max campaign, reach customers most likely to buy from you", "google-analytics-premium")), 1),
                createTextVNode(" " + toDisplayString(unref(__2)(
                  " across Google—including Search, YouTube, Gmail, and more—wherever they are searching or browsing for what you offer ",
                  "google-analytics-premium"
                )), 1)
              ]),
              createBaseVNode("li", null, [
                _cache[2] || (_cache[2] = createBaseVNode("span", { class: "monsterinsights-google-ads-welcome__check" }, "✔", -1)),
                createBaseVNode("strong", null, toDisplayString(unref(__2)("Get personalized recommendations", "google-analytics-premium")), 1),
                createTextVNode(" " + toDisplayString(unref(__2)(
                  " to help you connect with the right audience and high intent customers",
                  "google-analytics-premium"
                )), 1)
              ]),
              createBaseVNode("li", null, [
                _cache[3] || (_cache[3] = createBaseVNode("span", { class: "monsterinsights-google-ads-welcome__check" }, "✔", -1)),
                createBaseVNode("strong", null, toDisplayString(unref(__2)("Manage your campaign performance", "google-analytics-premium")), 1),
                createTextVNode(" " + toDisplayString(unref(__2)(" and track conversions all within MonsterInsights", "google-analytics-premium")), 1)
              ])
            ]),
            createBaseVNode("p", _hoisted_4$1, [
              createBaseVNode("span", {
                innerHTML: strings.value.disclaimer
              }, null, 8, _hoisted_5$1)
            ]),
            createBaseVNode("button", {
              class: "monsterinsights-button monsterinsights-google-ads-welcome__cta",
              onClick: _cache[0] || (_cache[0] = ($event) => emit("completeOnboarding"))
            }, toDisplayString(unref(__2)("Get Started", "google-analytics-premium")), 1),
            createBaseVNode("p", _hoisted_6$1, toDisplayString(unref(__2)(
              "If you sell products, make sure you have a Merchant Center Account before starting a campaign so you can promote your products with Google Shopping Ads and achieve the best performance.",
              "google-analytics-premium"
            )), 1),
            createBaseVNode("p", {
              class: "monsterinsights-google-ads-welcome__disclaimer",
              innerHTML: strings.value.google_ads_policies
            }, null, 8, _hoisted_7$1)
          ]),
          createBaseVNode("div", _hoisted_8$1, [
            createBaseVNode("img", {
              class: "monsterinsights-google-ads-welcome__img",
              src: unref(welcomeImage),
              alt: "Google Ads Welcome"
            }, null, 8, _hoisted_9$1)
          ])
        ])
      ]);
    };
  }
};
const _hoisted_1 = {
  key: 0,
  class: "notice notice-info monsterinsights-ads-loading"
};
const _hoisted_2 = {
  key: 1,
  class: "monsterinsights-ads-form monsterinsights-ads-error"
};
const _hoisted_3 = ["innerHTML"];
const _hoisted_4 = { key: 1 };
const _hoisted_5 = { key: 2 };
const _hoisted_6 = {
  key: 0,
  id: "monsterinsights-ads-form",
  class: "monsterinsights-ads-form"
};
const _hoisted_7 = { class: "radio-group" };
const _hoisted_8 = {
  class: "monsterinsights-button",
  type: "submit"
};
const _hoisted_9 = { key: 1 };
const _hoisted_10 = { class: "monsterinsights-ads-form" };
const _hoisted_11 = ["innerHTML"];
const _hoisted_12 = ["innerHTML"];
const _hoisted_13 = ["innerHTML"];
const _hoisted_14 = { key: 3 };
const _sfc_main = {
  __name: "SettingsGoogleAds-Pro",
  setup(__props) {
    const { __: __2, sprintf } = wp.i18n;
    const { auth, isNetwork } = useAuth();
    const onboardingSteps = [
      "physical_products_check",
      "physical_products_confirmation",
      "welcome_screen",
      "pax_app"
    ];
    const currentStep = ref(null);
    const isLoading = ref(false);
    const error = ref(null);
    const accessToken = ref(null);
    const adsSettings = ref({
      physical_products: null,
      user_onboarded: false,
      woogle_url: null
    });
    const showConversionNotice = ref(false);
    const strings = computed(() => ({
      loading_google_ads: __2("Loading Google Ads...", "google-analytics-for-wordpress"),
      auth_failed: __2(
        "To use this feature, please reconnect to MonsterInsights.",
        "google-analytics-for-wordpress"
      ),
      load_failed: __2(
        "Failed to load Google Ads integration. Please check your internet connection and try again.",
        "google-analytics-for-wordpress"
      ),
      init_failed: __2(
        "Google Ads integration failed to initialize properly. Please try again.",
        "google-analytics-for-wordpress"
      ),
      script_failed: __2(
        "Failed to load Google Ads integration script. Please check your internet connection and try again.",
        "google-analytics-for-wordpress"
      ),
      woogle_detected: sprintf(
        __2(
          "%sGoogle for WooCommerce Plugin user?%s <br><br> To maximize your Google Ads performance, we recommend launching an Ads campaign with Google for WooCommerce. Get started in a few easy steps %shere%s!",
          "google-analytics-for-wordpress"
        ),
        '<strong style="font-size: 14px">',
        "</strong>",
        '<a href="https://woocommerce.com/products/google-listings-and-ads/" target="_blank">',
        "</a>"
      ),
      physical_products_confirmation_yes: sprintf(
        __2(
          "%sSelling physical products?%s <br><br> For the best campaign results, we recommend using the %sGoogle Ads%s platform directly, as this app has limited capabilities for e-commerce businesses.",
          "google-analytics-for-wordpress"
        ),
        '<strong style="font-size: 14px">',
        "</strong>",
        '<a href="https://business.google.com/us/google-ads/" target="_blank">',
        "</a>"
      ),
      physical_products_confirmation_no: sprintf(
        __2(
          "%sSelling digital goods or services?%s <br><br> Great! Launch a Performance Max campaign in just a few minutes by clicking on the Get Started button on the next page.",
          "google-analytics-for-wordpress"
        ),
        '<strong style="font-size: 14px">',
        "</strong>"
      )
    }));
    const authFailedMessage = computed(() => {
      const link = `<a href="${getMiGlobal("exit_url", "#")}">${__2(
        "reconnect to MonsterInsights",
        "google-analytics-premium"
      )}</a>`;
      return __2("To use this feature, please", "google-analytics-premium") + " " + link + ".";
    });
    const authReady = computed(() => {
      const a = auth.value || {};
      if (isNetwork.value) {
        return typeof a.network_v4 !== "undefined" || typeof a.network_manual_v4 !== "undefined";
      }
      return typeof a.v4 !== "undefined" || typeof a.manual_v4 !== "undefined";
    });
    const isAuthed = computed(() => !!getMiGlobal("authed", false));
    const physicalProducts = computed({
      get() {
        const v = adsSettings.value.physical_products;
        if (typeof v === "undefined" || v === null) return null;
        return v === true ? "yes" : "no";
      },
      set(value) {
        adsSettings.value.physical_products = value === "yes";
      }
    });
    async function completeOnboarding() {
      adsSettings.value.user_onboarded = true;
      try {
        await api.updateAdsSetting("user_onboarded", true);
      } catch (_e) {
        console.error("Failed to persist user_onboarded");
      }
      currentStep.value = onboardingSteps[3];
    }
    function onPhysicalProductsConfirmation() {
      currentStep.value = onboardingSteps[2];
    }
    async function fetchAdsSettings2() {
      isLoading.value = true;
      try {
        const response = await api.fetchAdsSettings();
        if (response) {
          adsSettings.value = { ...adsSettings.value, ...response };
          if (adsSettings.value.user_onboarded) {
            checkConversions2();
            currentStep.value = onboardingSteps[3];
            return;
          }
          if (adsSettings.value.is_woo_active === false) {
            currentStep.value = onboardingSteps[2];
            return;
          }
          if (adsSettings.value.physical_products === true) {
            currentStep.value = onboardingSteps[1];
            return;
          }
          currentStep.value = onboardingSteps[0];
        }
      } catch (e) {
        error.value = e?.message || __2("Failed to fetch Google Ads settings.", "google-analytics-for-wordpress");
      } finally {
        isLoading.value = false;
      }
    }
    async function savePhysicalProducts() {
      isLoading.value = true;
      try {
        const value = physicalProducts.value === "yes";
        await api.updateAdsSetting("physical_products", value);
        currentStep.value = onboardingSteps[1];
      } catch (e) {
        error.value = e?.message || __2("Failed to update setting.", "google-analytics-for-wordpress");
      } finally {
        isLoading.value = false;
      }
    }
    async function saveTrackingId(value) {
      try {
        const response = await api.updateAdsSetting("conversion_tracking_id", value);
        if (response && response.success !== false) {
          adsSettings.value.conversion_tracking_id = value;
        }
      } catch (e) {
        error.value = e?.message || __2("Failed to update settings.", "google-analytics-for-wordpress");
      }
    }
    async function saveAccountInfo(data) {
      for (const [key, value] of Object.entries(data)) {
        try {
          await api.updateAdsSetting(key, value);
          adsSettings.value[key] = value;
        } catch (e) {
          console.error(`Failed to save ${key}:`, e);
        }
      }
    }
    async function checkConversions2() {
      try {
        const response = await api.checkConversions();
        showConversionNotice.value = !response?.has_conversions;
      } catch (e) {
        console.error("Failed to check conversions:", e);
      }
    }
    async function initialize() {
      if (!isAuthed.value) {
        error.value = strings.value.auth_failed;
        return;
      }
      error.value = null;
      try {
        const token = await api.fetchAccessToken();
        accessToken.value = token;
        await nextTick();
        initPax();
      } catch (e) {
        if (e?.code === api.AUTH_FAILED_CODE || e?.message === "auth_failed") {
          error.value = strings.value.auth_failed;
        } else {
          error.value = e?.message || strings.value.load_failed;
        }
      }
    }
    function initPax() {
      isLoading.value = true;
      const paxConfig = {
        authAccess: {
          oauthTokenAccess: { token: accessToken.value }
        },
        clientConfig: {
          contentContainer: "#google-ads-pax-container"
        }
      };
      const paxServices = {
        authenticationService: {
          get: (_request) => Promise.resolve({ accessToken: accessToken.value }),
          fix: (_request) => Promise.resolve({ retryReady: true })
        },
        accountStatusService: {
          disconnect: (_request) => Promise.resolve({})
        },
        businessService: {
          getBusinessInfo: (_request) => Promise.resolve({
            businessName: getMiGlobal("site_name", ""),
            businessUrl: getMiGlobal("site_url", "")
          }),
          fixBusinessInfo: (_request) => Promise.resolve({ retryReady: true })
        },
        conversionTrackingService: {
          getSupportedConversionLabels: (_request) => Promise.resolve({
            conversionLabels: ["purchase", "contact", "submit_lead_form", "sign_up"]
          }),
          getSupportedConversionTrackingTypes: async () => ({
            conversionTrackingTypes: ["TYPE_CONVERSION_EVENT"]
          })
        },
        campaignService: {
          notifyNewCampaignCreated: async (request) => {
            const campaignId = request.campaignId;
            await saveAccountInfo({ campaign_id: campaignId });
            await checkConversions2();
            return Promise.resolve({});
          }
        },
        termsAndConditionsService: {
          notify: (_request) => Promise.resolve({})
        }
      };
      const sc = document.createElement("script");
      sc.src = "https://www.gstatic.com/pax/latest/pax_integrator.js";
      document.body.append(sc);
      sc.onload = async () => {
        try {
          const adsAppHandler = await google.ads.integration.integrator.launchGoogleAds(
            paxConfig,
            paxServices
          );
          if (!adsAppHandler) {
            throw new Error(strings.value.init_failed);
          }
          const ctService = adsAppHandler.getServices().conversionTrackingIdService;
          if (ctService && typeof ctService.getConversionTrackingId === "function") {
            const response = await ctService.getConversionTrackingId({});
            isLoading.value = false;
            if (response?.conversionTrackingId) {
              adsSettings.value.conversion_tracking_id = response.conversionTrackingId;
              saveTrackingId(response.conversionTrackingId);
            }
          }
          const accountService = adsAppHandler.getServices().accountService;
          if (accountService && typeof accountService.getAccountId === "function") {
            const accountInfo = await accountService.getAccountId({});
            const { customerId, externalCustomerId } = accountInfo || {};
            await saveAccountInfo({
              customer_id: customerId,
              external_customer_id: externalCustomerId
            });
          } else {
            console.warn("PAX accountService.getAccountId not available");
          }
        } catch (e) {
          error.value = e?.message || strings.value.load_failed;
        }
      };
      sc.onerror = () => {
        error.value = strings.value.script_failed;
      };
    }
    watch(currentStep, (newVal) => {
      if (newVal === onboardingSteps[3]) {
        initialize();
      }
    });
    onMounted(async () => {
      const url = new URL(window.location.href);
      if (url.searchParams.get("mi_reloaded") !== "true") {
        url.searchParams.set("mi_reloaded", "true");
        window.location.replace(url.toString());
        return;
      }
      url.searchParams.delete("mi_reloaded");
      history.replaceState(null, "", url.toString());
      await fetchAdsSettings2();
    });
    return (_ctx, _cache) => {
      return openBlock(), createElementBlock("div", null, [
        isLoading.value ? (openBlock(), createElementBlock("div", _hoisted_1, [
          _cache[2] || (_cache[2] = createBaseVNode("div", { class: "monsterinsights-ads-loading-spinner" }, null, -1)),
          createBaseVNode("p", null, toDisplayString(strings.value.loading_google_ads), 1)
        ])) : createCommentVNode("", true),
        error.value ? (openBlock(), createElementBlock("div", _hoisted_2, [
          error.value === strings.value.auth_failed ? (openBlock(), createElementBlock("p", {
            key: 0,
            innerHTML: authFailedMessage.value
          }, null, 8, _hoisted_3)) : (openBlock(), createElementBlock("p", _hoisted_4, toDisplayString(error.value), 1))
        ])) : createCommentVNode("", true),
        !error.value && authReady.value ? withDirectives((openBlock(), createElementBlock("div", _hoisted_5, [
          currentStep.value === "physical_products_check" ? (openBlock(), createElementBlock("div", _hoisted_6, [
            createBaseVNode("h2", null, toDisplayString(unref(__2)("Google Ads Setup", "google-analytics-premium")), 1),
            createBaseVNode("form", {
              onSubmit: withModifiers(savePhysicalProducts, ["prevent"])
            }, [
              createBaseVNode("label", null, toDisplayString(unref(__2)("Do you sell physical products?", "google-analytics-premium")), 1),
              createBaseVNode("div", _hoisted_7, [
                createBaseVNode("label", null, [
                  withDirectives(createBaseVNode("input", {
                    "onUpdate:modelValue": _cache[0] || (_cache[0] = ($event) => physicalProducts.value = $event),
                    type: "radio",
                    name: "physical_products",
                    value: "yes"
                  }, null, 512), [
                    [vModelRadio, physicalProducts.value]
                  ]),
                  createTextVNode(" " + toDisplayString(unref(__2)("Yes", "google-analytics-premium")), 1)
                ]),
                createBaseVNode("label", null, [
                  withDirectives(createBaseVNode("input", {
                    "onUpdate:modelValue": _cache[1] || (_cache[1] = ($event) => physicalProducts.value = $event),
                    type: "radio",
                    name: "physical_products",
                    value: "no"
                  }, null, 512), [
                    [vModelRadio, physicalProducts.value]
                  ]),
                  createTextVNode(" " + toDisplayString(unref(__2)("No", "google-analytics-premium")), 1)
                ])
              ]),
              createBaseVNode("button", _hoisted_8, toDisplayString(unref(__2)("Continue", "google-analytics-premium")), 1)
            ], 32)
          ])) : currentStep.value === "physical_products_confirmation" ? (openBlock(), createElementBlock("div", _hoisted_9, [
            createBaseVNode("div", _hoisted_10, [
              adsSettings.value.is_woogle_active === true ? (openBlock(), createElementBlock("p", {
                key: 0,
                innerHTML: strings.value.woogle_detected
              }, null, 8, _hoisted_11)) : physicalProducts.value === "yes" ? (openBlock(), createElementBlock("p", {
                key: 1,
                innerHTML: strings.value.physical_products_confirmation_yes
              }, null, 8, _hoisted_12)) : (openBlock(), createElementBlock(Fragment, { key: 2 }, [
                createBaseVNode("p", {
                  innerHTML: strings.value.physical_products_confirmation_no
                }, null, 8, _hoisted_13),
                createBaseVNode("button", {
                  class: "monsterinsights-button",
                  onClick: onPhysicalProductsConfirmation
                }, toDisplayString(unref(__2)("Continue", "google-analytics-premium")), 1)
              ], 64))
            ])
          ])) : currentStep.value === "welcome_screen" ? (openBlock(), createBlock(_sfc_main$1, {
            key: 2,
            onCompleteOnboarding: completeOnboarding
          })) : currentStep.value === "pax_app" ? (openBlock(), createElementBlock("div", _hoisted_14, [..._cache[3] || (_cache[3] = [
            createBaseVNode("div", {
              id: "google-ads-pax-container",
              class: "google-ads-pax-container"
            }, null, -1)
          ])])) : createCommentVNode("", true)
        ], 512)), [
          [vShow, !isLoading.value]
        ]) : createCommentVNode("", true)
      ]);
    };
  }
};
export {
  _sfc_main as default
};
