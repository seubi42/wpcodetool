const __vite__mapDeps=(i,m=__vite__mapDeps,d=(m.f||(m.f=["./chunks/monsterinsights-SettingsNetwork-4El_NYWx.js","./chunks/globals-C78yxw47.js","./chunks/sanitize-CE5uy2g6.js","./chunks/AppOverlays-Dpqzx_3P.js","../css/main-monsterinsights-BqoTIBUO.css","./chunks/license-D2vquWPE.js","./chunks/useNotices-CwGRydDs.js","./chunks/SettingsBlock-BINMceN_.js","../css/main-monsterinsights-DCic6-mZ.css","./chunks/SettingsInputCheckbox-kcgcZSVs.js","./chunks/settings-WrluSu6E.js","./chunks/monsterinsights-Pro-B9tAtBLR.js","./chunks/TheAppHeader-CJeDav-v.js","./chunks/addons-rxJejcjK.js","./chunks/Modal-D1CHxx1Y.js","../css/main-monsterinsights-DRT55mf0.css","../css/main-monsterinsights-CqVDke3g.css","./chunks/SettingsInfoTooltip-QCvq3iSl.js","./chunks/monsterinsights-SettingsInputAuthenticate-Pro-C9VT_FUk.js","./chunks/auth-D7PLXWYn.js","./chunks/SettingsInputText-IF6LAjxY.js","../css/main-monsterinsights-D0h0-uQX.css","./chunks/SettingsLiteUpsellLarge-5wNFfHoF.js","./chunks/LaunchWizardButton-CiLSAg2u.js","../css/main-monsterinsights-BL4OIBre.css","./chunks/AddonsPage-C7x0CUNP.js","../css/main-monsterinsights-CGAGRmkt.css","./chunks/AboutShell-CPthKjkw.js","./chunks/monsterinsights-AboutTabAboutUs-CaUxB2Ob.js","./chunks/AboutBlock-DHhV3aBR.js","./chunks/monsterinsights-AboutTabGettingStarted-CLY7HJMi.js","./chunks/monsterinsights-AboutTabLiteVsPro-4InGgP2_.js"])))=>i.map(i=>d[i]);
import { D as watch, z as onMounted, r as resolveComponent, o as openBlock, c as createElementBlock, F as Fragment, b as createVNode, E as withCtx, G as createBlock, v as createCommentVNode, a as createBaseVNode, _ as _sfc_main$8, k as normalizeClass, p as computed, d as createApp, i as installOverlays } from "./chunks/AppOverlays-Dpqzx_3P.js";
import { b as useRoute, e as _sfc_main$1, _ as _sfc_main$3, c as createRouter, a as createWebHashHistory } from "./chunks/TheAppHeader-CJeDav-v.js";
import { _ as __vitePreload, s as setupPinia } from "./chunks/sanitize-CE5uy2g6.js";
import { _ as _sfc_main$2, a as _sfc_main$4, b as _sfc_main$5, c as _sfc_main$6, u as useAuth } from "./chunks/monsterinsights-Pro-B9tAtBLR.js";
import { c as useAddonsStore, a as useToast, _ as _sfc_main$7 } from "./chunks/addons-rxJejcjK.js";
import { u as useSettingsStore } from "./chunks/settings-WrluSu6E.js";
import { u as useLicenseStore } from "./chunks/license-D2vquWPE.js";
import { l as isPro } from "./chunks/globals-C78yxw47.js";
import { a as authApi } from "./chunks/auth-D7PLXWYn.js";
import "./chunks/useNotices-CwGRydDs.js";
import "./chunks/Modal-D1CHxx1Y.js";
const _hoisted_5 = {
  key: 0,
  class: "monsterinsights-navigation-bar"
};
const _hoisted_6 = { class: "monsterinsights-container" };
const _hoisted_7 = { class: "monsterinsights-navigation-bar" };
const _hoisted_8 = { class: "monsterinsights-container" };
const _hoisted_9 = { class: "monsterinsights-main-navigation" };
const _sfc_main = {
  __name: "AppNetwork",
  setup(__props) {
    const route = useRoute();
    const settingsStore = useSettingsStore();
    const licenseStore = useLicenseStore();
    const addonsStore = useAddonsStore();
    const { updateAuth } = useAuth();
    const { loadingToast, closeToast } = useToast();
    const routeClass = computed(() => {
      return "monsterinsights-admin-page monsterinsights-settings-panel monsterinsights-settings-panel-network monsterinsights-path-" + route.name;
    });
    const isAddonsRoute = computed(() => route.name === "addons");
    const isAboutRoute = computed(() => typeof route.name === "string" && route.name.startsWith("about"));
    const showSave = computed(() => !isAddonsRoute.value && !isAboutRoute.value);
    let cached_settings_link = null;
    let cached_addons_link = null;
    let cached_about_link = null;
    function updateAdminMenuHighlight(name) {
      [cached_settings_link, cached_addons_link, cached_about_link].forEach((el) => {
        if (el) el.parentElement.classList.remove("current");
      });
      if (name === "addons" && cached_addons_link) {
        cached_addons_link.parentElement.classList.add("current");
      } else if (typeof name === "string" && name.indexOf("about") >= 0 && cached_about_link) {
        cached_about_link.parentElement.classList.add("current");
      } else if (cached_settings_link) {
        cached_settings_link.parentElement.classList.add("current");
      }
    }
    watch(() => route.name, (name) => updateAdminMenuHighlight(name));
    onMounted(() => {
      const settings_links = document.querySelectorAll('[href="admin.php?page=monsterinsights_network"]');
      cached_settings_link = settings_links[settings_links.length > 1 ? 1 : 0] || null;
      cached_addons_link = document.querySelector('[href*="monsterinsights_network#/addons"]');
      cached_about_link = document.querySelector('[href*="monsterinsights_network#/about"]');
      updateAdminMenuHighlight(route.name);
      settings_links.forEach((link) => {
        if (!link.href.includes("#")) {
          link.href = link.href + "#/";
        }
      });
    });
    onMounted(async () => {
      addonsStore.enableAddonNotices();
      loadingToast();
      try {
        await Promise.all([
          settingsStore.fetchSettings(),
          authApi.fetchAuth().then((data) => {
            if (data) updateAuth(data);
          }).catch(() => {
          }),
          addonsStore.fetchAddonsAction().catch(() => {
          }),
          isPro() ? licenseStore.fetchLicenseData().catch(() => {
          }) : Promise.resolve()
        ]);
      } finally {
        closeToast();
      }
    });
    return (_ctx, _cache) => {
      const _component_RouterView = resolveComponent("RouterView");
      return openBlock(), createElementBlock("div", {
        class: normalizeClass(routeClass.value)
      }, [
        (openBlock(), createElementBlock(Fragment, { key: 1 }, [
          createVNode(_sfc_main$3, null, {
            "header-right": withCtx(() => [
              createVNode(_sfc_main$1),
              showSave.value ? (openBlock(), createBlock(_sfc_main$2, { key: 0 })) : createCommentVNode("", true)
            ]),
            _: 1
          }),
          isAboutRoute.value ? (openBlock(), createElementBlock("div", _hoisted_5, [
            createBaseVNode("div", _hoisted_6, [
              createVNode(_sfc_main$4)
            ])
          ])) : createCommentVNode("", true),
          isAddonsRoute.value ? (openBlock(), createElementBlock(Fragment, { key: 1 }, [
            createBaseVNode("div", _hoisted_7, [
              createBaseVNode("div", _hoisted_8, [
                createBaseVNode("nav", _hoisted_9, [
                  createVNode(_sfc_main$5)
                ])
              ])
            ]),
            createVNode(_sfc_main$6)
          ], 64)) : createCommentVNode("", true)
        ], 64)),
        createVNode(_sfc_main$7),
        createVNode(_component_RouterView),
        createVNode(_sfc_main$8)
      ], 2);
    };
  }
};
const { __ } = wp.i18n;
const SettingsNetwork = () => __vitePreload(() => import("./chunks/monsterinsights-SettingsNetwork-4El_NYWx.js"), true ? __vite__mapDeps([0,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24]) : void 0, import.meta.url);
const AddonsPage = () => __vitePreload(() => import("./chunks/AddonsPage-C7x0CUNP.js"), true ? __vite__mapDeps([25,12,3,4,2,13,6,14,15,5,1,26]) : void 0, import.meta.url);
const AboutShell = () => __vitePreload(() => import("./chunks/AboutShell-CPthKjkw.js"), true ? __vite__mapDeps([27,3,4]) : void 0, import.meta.url);
const AboutTabAboutUs = () => __vitePreload(() => import("./chunks/monsterinsights-AboutTabAboutUs-CaUxB2Ob.js"), true ? __vite__mapDeps([28,13,6,3,4,2,14,15,29,12,5,1]) : void 0, import.meta.url);
const AboutTabGettingStarted = () => __vitePreload(() => import("./chunks/monsterinsights-AboutTabGettingStarted-CLY7HJMi.js"), true ? __vite__mapDeps([30,5,3,4,6,1,2,29,23,13,14,15]) : void 0, import.meta.url);
const AboutTabLiteVsPro = () => __vitePreload(() => import("./chunks/monsterinsights-AboutTabLiteVsPro-4InGgP2_.js"), true ? __vite__mapDeps([31,1,2,3,4,29]) : void 0, import.meta.url);
const routes = [
  {
    path: "/",
    name: "general",
    component: SettingsNetwork,
    meta: {
      title: __("Network Settings", "google-analytics-premium")
    }
  },
  {
    path: "/addons",
    name: "addons",
    component: AddonsPage,
    meta: {
      title: __("Addons", "google-analytics-premium")
    }
  },
  {
    path: "/about",
    component: AboutShell,
    meta: {
      title: __("About Us", "google-analytics-premium")
    },
    children: [
      {
        path: "",
        name: "about-us",
        component: AboutTabAboutUs,
        meta: { title: __("About Us", "google-analytics-premium") }
      },
      {
        path: "getting-started",
        name: "about-getting-started",
        component: AboutTabGettingStarted,
        meta: { title: __("Getting Started", "google-analytics-premium") }
      },
      {
        path: "lite-vs-pro",
        name: "about-lite-vs-pro",
        component: AboutTabLiteVsPro,
        meta: { title: __("Lite vs Pro", "google-analytics-premium") }
      }
    ]
  },
  // Catch-all redirect - MUST be last
  {
    path: "/:pathMatch(.*)*",
    redirect: "/"
  }
];
const router = createRouter({
  history: createWebHashHistory(),
  routes,
  scrollBehavior() {
    return { top: 0, left: 0 };
  }
});
const app = createApp(_sfc_main);
app.use(router);
installOverlays(app);
setupPinia(app);
const mountEl = document.getElementById("monsterinsights-vue-network-settings");
if (mountEl) {
  app.mount(mountEl);
}
