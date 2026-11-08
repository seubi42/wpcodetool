const __vite__mapDeps=(i,m=__vite__mapDeps,d=(m.f||(m.f=["./chunks/DashboardList.pro-oXaAsE-8.js","./chunks/TheAppHeader-CJeDav-v.js","./chunks/AppOverlays-Dpqzx_3P.js","../css/main-monsterinsights-BqoTIBUO.css","./chunks/sanitize-CE5uy2g6.js","./chunks/default-i18n-KrIlCc2E.js","./chunks/monsterinsights-style-D8E96NnX.js","./chunks/Icon-BEJfUbqO.js","../css/main-monsterinsights-DdCOUrGJ.css","./chunks/customViews-CyVdMjdZ.js","./chunks/globals-C78yxw47.js","../css/main-monsterinsights-2PRgBvvs.css","./chunks/DashboardCreate-KZACru4X.js","./chunks/useSampleData-DVG5ejSD.js","./chunks/useAuthGate-CIPLEksz.js","./chunks/flatpickr-Dd43BNxQ.js","../css/main-monsterinsights-CMSe2SYd.css","./chunks/dateIntervals-BPoui_3H.js","../css/main-monsterinsights-h4_uXCXe.css","./chunks/LoadingSpinnerInline-DLpn9bnW.js","../css/main-monsterinsights-CKJSBouy.css","./chunks/vue3-apexcharts-DUo9cBii.js","../css/main-monsterinsights-DcECXQxs.css","./chunks/useChartColors-DtUshN69.js","./chunks/ApexBarChart-BHrc13Ap.js","../css/main-monsterinsights-DAYzOZ5f.css","./chunks/Modal-D1CHxx1Y.js","../css/main-monsterinsights-DRT55mf0.css","./chunks/license-D2vquWPE.js","./chunks/useNotices-CwGRydDs.js","../css/main-monsterinsights-gsHLWIGr.css","./chunks/ReAuthModal-BCbeLdss.js","./chunks/auth-D7PLXWYn.js","../css/main-monsterinsights-BVgKjrnK.css","../css/main-monsterinsights-C_BN-_xE.css","./chunks/DashboardEdit-CrKS66eZ.js","./chunks/DashboardView-DP0ciQqH.js"])))=>i.map(i=>d[i]);
import { c as createElementBlock, a as createBaseVNode, b as createVNode, r as resolveComponent, o as openBlock, _ as _sfc_main$2, d as createApp, i as installOverlays, g as getMiGlobal } from "./chunks/AppOverlays-Dpqzx_3P.js";
import { _ as _sfc_main$1, c as createRouter, a as createWebHashHistory } from "./chunks/TheAppHeader-CJeDav-v.js";
import { _ as __vitePreload, s as setupPinia } from "./chunks/sanitize-CE5uy2g6.js";
import "./chunks/default-i18n-KrIlCc2E.js";
const _hoisted_1 = { class: "mi-custom-dashboard-app monsterinsights-app-surface" };
const _hoisted_2 = { id: "monsterinsights-app" };
const _hoisted_3 = { class: "monsterinsights-dashboard-content" };
const _hoisted_4 = { class: "monsterinsights-container" };
const _sfc_main = {
  __name: "App",
  setup(__props) {
    return (_ctx, _cache) => {
      const _component_RouterView = resolveComponent("RouterView");
      return openBlock(), createElementBlock("div", _hoisted_1, [
        createBaseVNode("div", _hoisted_2, [
          createBaseVNode("div", _hoisted_3, [
            createVNode(_sfc_main$1),
            createBaseVNode("div", _hoisted_4, [
              createVNode(_component_RouterView)
            ])
          ])
        ]),
        createVNode(_sfc_main$2)
      ]);
    };
  }
};
const DashboardList = () => __vitePreload(() => import("./chunks/DashboardList.pro-oXaAsE-8.js"), true ? __vite__mapDeps([0,1,2,3,4,5,6,7,8,9,10,11]) : void 0, import.meta.url);
const DashboardCreate = () => __vitePreload(() => import("./chunks/DashboardCreate-KZACru4X.js"), true ? __vite__mapDeps([12,1,2,3,4,5,13,7,8,14,15,16,17,10,18,9,6,19,20,21,22,23,24,25,26,27,28,29,30,31,32,33,34]) : void 0, import.meta.url);
const DashboardEdit = () => __vitePreload(() => import("./chunks/DashboardEdit-CrKS66eZ.js"), true ? __vite__mapDeps([35,12,1,2,3,4,5,13,7,8,14,15,16,17,10,18,9,6,19,20,21,22,23,24,25,26,27,28,29,30,31,32,33,34]) : void 0, import.meta.url);
const DashboardView = () => __vitePreload(() => import("./chunks/DashboardView-DP0ciQqH.js"), true ? __vite__mapDeps([36,1,2,3,4,5,13,7,8,14,15,16,17,10,18,9,6,19,20,21,22,23,24,25,26,27,28,29,30,31,32,33]) : void 0, import.meta.url);
const routes = [
  {
    path: "/",
    redirect: "/dashboards"
  },
  {
    path: "/dashboards",
    name: "dashboard-list",
    component: DashboardList,
    meta: {
      title: "Custom Views",
      requiresAuth: true
    }
  },
  {
    path: "/dashboards/add",
    name: "dashboard-add",
    component: DashboardList,
    meta: {
      title: "Add Custom View",
      requiresAuth: true,
      requiresEdit: true,
      showTemplateSelector: true
    }
  },
  {
    path: "/dashboards/new",
    name: "dashboard-create",
    component: DashboardCreate,
    meta: {
      title: "Create View",
      requiresAuth: true,
      requiresEdit: true
    }
  },
  {
    path: "/dashboards/:id/edit",
    name: "dashboard-edit",
    component: DashboardEdit,
    props: true,
    meta: {
      title: "Edit View",
      requiresAuth: true,
      requiresEdit: true
    }
  },
  {
    path: "/dashboards/:id/view",
    name: "dashboard-view",
    component: DashboardView,
    props: true,
    meta: {
      title: "View",
      requiresAuth: true
    }
  },
  {
    path: "/:pathMatch(.*)*",
    redirect: "/dashboards"
  }
];
function hasCustomViewsAccess() {
  {
    const license = getMiGlobal("license", {});
    const licenseType = (license.type || "").toLowerCase();
    return licenseType === "pro" || licenseType === "elite";
  }
}
const router = createRouter({
  history: createWebHashHistory(),
  routes
});
router.beforeEach((to, _from, next) => {
  if (!hasCustomViewsAccess() && (to.name === "dashboard-list" || to.path === "/dashboards")) {
    next({ name: "dashboard-view", params: { id: "sample" } });
    return;
  }
  next();
});
const app = createApp(_sfc_main);
app.use(router);
setupPinia(app);
installOverlays(app);
app.config.errorHandler = (err, _vm, info) => {
  console.error("Custom View Error:", err, info);
};
app.mount("#monsterinsights-custom-dashboard-app");
