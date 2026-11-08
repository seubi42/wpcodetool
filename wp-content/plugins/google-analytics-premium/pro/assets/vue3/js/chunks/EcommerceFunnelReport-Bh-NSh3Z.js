import { a6 as storeToRefs, D as watch, z as onMounted, o as openBlock, G as createBlock, E as withCtx, a as createBaseVNode, t as toDisplayString, u as unref, w as withDirectives, c as createElementBlock, F as Fragment, h as renderList, R as vModelSelect, b as createVNode, k as normalizeClass, n as normalizeStyle, v as createCommentVNode, B as createTextVNode, g as getMiGlobal, l as ref, p as computed } from "./AppOverlays-Dpqzx_3P.js";
import { _ as __ } from "./default-i18n-KrIlCc2E.js";
import { u as useOverviewReportStore, j as fetchFunnelData, b as buildApiFilters } from "../reports-BheoY72J.js";
import { d as dateIntervals } from "./dateIntervals-BPoui_3H.js";
import { u as useReportPermissions } from "./useReportPermissions-B-NHV_0o.js";
import { c as generateFunnelSample } from "./ecommerceSampleData-CfcmM5cV.js";
import { g as getFunnelBarStyle, _ as _sfc_main$1, h as hasFunnelUsers, b as buildFunnelEmptyState, n as normalizeFunnelApiResponse } from "./useFunnelData-v4UcqxR2.js";
import { I as Icon } from "./Icon-BEJfUbqO.js";
import { L as LoadingSpinnerInline } from "./LoadingSpinnerInline-DLpn9bnW.js";
import { R as ReportPageLayout } from "./ReportPageLayout-DqvrR5b3.js";
import "./TheAppHeader-CJeDav-v.js";
import "./sanitize-CE5uy2g6.js";
import "./addons-rxJejcjK.js";
import "./useNotices-CwGRydDs.js";
import "./Modal-D1CHxx1Y.js";
import "./useAuthGate-CIPLEksz.js";
import "./flatpickr-Dd43BNxQ.js";
import "./globals-C78yxw47.js";
import "./UniversallyPromo-zip1hHZv.js";
import "./ReAuthModal-BCbeLdss.js";
import "./auth-D7PLXWYn.js";
const _hoisted_1 = { class: "monsterinsights-ecommerce-funnel-report__header" };
const _hoisted_2 = { class: "monsterinsights-ecommerce-funnel-report__funnel-select-wrapper" };
const _hoisted_3 = { class: "monsterinsights-ecommerce-funnel-report__label" };
const _hoisted_4 = ["disabled"];
const _hoisted_5 = { value: "" };
const _hoisted_6 = ["value"];
const _hoisted_7 = {
  key: 0,
  class: "monsterinsights-ecommerce-funnel-report__loading"
};
const _hoisted_8 = {
  key: 1,
  class: "monsterinsights-ecommerce-funnel-report__error"
};
const _hoisted_9 = {
  key: 2,
  class: "monsterinsights-report-ecommerce-funnel-chart-wrapper"
};
const _hoisted_10 = { class: "monsterinsights-report-ecommerce-funnel-box-header" };
const _hoisted_11 = { class: "monsterinsights-report-ecommerce-funnel-box-header-col-1" };
const _hoisted_12 = { class: "monsterinsights-report-ecommerce-funnel-box-header-col-2" };
const _hoisted_13 = { class: "monsterinsights-report-ecommerce-funnel-box-body" };
const _hoisted_14 = { class: "monsterinsights-report-ecommerce-funnel-box-body-col-1" };
const _hoisted_15 = { class: "monsterinsights-report-ecommerce-funnel-css-chart" };
const _hoisted_16 = { class: "monsterinsights-report-ecommerce-funnel-css-chart__value" };
const _hoisted_17 = { class: "monsterinsights-report-ecommerce-funnel-box-body-col-3" };
const _hoisted_18 = {
  key: 0,
  class: "monsterinsights-report-ecommerce-funnel-box-body-col-3-item"
};
const _hoisted_19 = { class: "monsterinsights-report-ecommerce-funnel-box-body-col-3-item-inner" };
const _hoisted_20 = { class: "monsterinsights-report-ecommerce-funnel-box-body-col-4" };
const _hoisted_21 = {
  key: 1,
  class: "monsterinsights-report-ecommerce-funnel-box-visibility-hidden"
};
const _hoisted_22 = {
  key: 3,
  class: "monsterinsights-overview-report-table"
};
const _hoisted_23 = { class: "monsterinsights-overview-report-table__header" };
const _hoisted_24 = { class: "monsterinsights-overview-report-table__title" };
const _hoisted_25 = { class: "monsterinsights-overview-report-table__table-wrapper" };
const _hoisted_26 = { class: "monsterinsights-overview-report-table__table" };
const _hoisted_27 = {
  key: 4,
  class: "monsterinsights-ecommerce-funnel-report__empty"
};
const _hoisted_28 = { class: "monsterinsights-ecommerce-funnel-report__empty-card" };
const _hoisted_29 = { class: "monsterinsights-ecommerce-funnel-report__empty-title" };
const _hoisted_30 = { class: "monsterinsights-ecommerce-funnel-report__empty-message" };
const _hoisted_31 = {
  key: 0,
  class: "monsterinsights-ecommerce-funnel-report__empty-hints"
};
const _hoisted_32 = {
  key: 1,
  class: "monsterinsights-ecommerce-funnel-report__empty-steps"
};
const _hoisted_33 = ["href"];
const _hoisted_34 = {
  key: 5,
  class: "monsterinsights-ecommerce-funnel-report__empty"
};
const _sfc_main = {
  __name: "EcommerceFunnelReport",
  setup(__props) {
    const overviewStore = useOverviewReportStore();
    const { dateRange, activeFilters: storeActiveFilters, activeDevice: storeActiveDevice } = storeToRefs(overviewStore);
    const { isBlocked } = useReportPermissions({ minTier: "pro" });
    const funnelLoading = ref(false);
    const funnelError = ref(null);
    const funnelDataRef = ref(null);
    const funnelModalOpen = ref(false);
    const selectedFunnelId = ref("");
    const funnelsInitializing = ref(true);
    watch(
      () => overviewStore.activeFunnelId,
      (newId) => {
        selectedFunnelId.value = newId || "";
      },
      { immediate: true }
    );
    const onFunnelChange = () => {
      overviewStore.setActiveFunnelId(selectedFunnelId.value || null);
    };
    const openFunnelModal = () => {
      funnelModalOpen.value = true;
    };
    const closeFunnelModal = () => {
      funnelModalOpen.value = false;
    };
    const activeFunnel = computed(() => overviewStore.activeFunnel);
    const funnelSteps = computed(() => {
      const data = funnelDataRef.value;
      if (!data || !Array.isArray(data.steps) || data.steps.length === 0) return [];
      return data.steps;
    });
    const funnelHasUsers = computed(() => hasFunnelUsers(funnelSteps.value));
    const emptyState = computed(
      () => buildFunnelEmptyState({
        start: dateRange.value?.start,
        end: dateRange.value?.end,
        configuredSteps: activeFunnel.value?.steps ?? []
      })
    );
    const applyLast7Days = () => {
      const last7 = dateIntervals.last7days;
      overviewStore.updateDateRange({
        interval: "last7days",
        start: last7.start.format("YYYY-MM-DD"),
        end: last7.end.format("YYYY-MM-DD")
      });
    };
    const apiFilters = computed(
      () => buildApiFilters(storeActiveFilters.value, storeActiveDevice.value)
    );
    const gradientColors = /* @__PURE__ */ (() => {
      return { c1: "#EDE5FF", c2: "#D0E8FF", c3: "#FFFFFF" };
    })();
    function getRowGradientStyle(barPercent) {
      if (barPercent <= 0) return {};
      const { c1, c2, c3 } = gradientColors;
      const mid = barPercent / 2;
      return {
        background: `linear-gradient(to right, ${c1} 0%, ${c2} ${mid}%, ${c3} ${barPercent}%, ${c3} 100%)`
      };
    }
    const funnelTableRows = computed(() => {
      const data = funnelDataRef.value;
      if (!data || !Array.isArray(data.steps) || data.steps.length === 0) return [];
      const steps = data.steps;
      const stepOneCount = steps[0] ? Number(steps[0].activeUsers ?? 0) : 0;
      const isLastStep = (idx) => idx === steps.length - 1;
      return steps.map((step, index) => {
        const pctOfStep1 = stepOneCount > 0 ? (step.activeUsers / stepOneCount * 100).toFixed(2) : "0.00";
        const barPercent = Math.max(10, Math.min(parseFloat(pctOfStep1) * 0.4, 100));
        return {
          stepName: step.stepName,
          activeUsers: `${step.activeUsers} (${pctOfStep1}%)`,
          completionRate: isLastStep(index) ? "--" : `${step.completionRate.toFixed(1)}%`,
          abandonments: isLastStep(index) ? "--" : String(step.abandonments),
          abandonmentRate: isLastStep(index) ? "--" : `${step.abandonmentRate.toFixed(1)}%`,
          breakdownCategory: step.breakdownCategory || "",
          barPercent
        };
      });
    });
    async function loadFunnelData() {
      const funnel = activeFunnel.value;
      const start = dateRange.value?.start;
      const end = dateRange.value?.end;
      if (!funnel || !start || !end || !funnel.steps || funnel.steps.length < 2) {
        funnelDataRef.value = null;
        funnelError.value = null;
        return;
      }
      if (isBlocked.value) {
        funnelDataRef.value = normalizeFunnelApiResponse(generateFunnelSample(), funnel.steps);
        return;
      }
      funnelLoading.value = true;
      funnelError.value = null;
      try {
        const data = await fetchFunnelData(
          { start, end },
          funnel,
          apiFilters.value
        );
        funnelDataRef.value = normalizeFunnelApiResponse(data, funnel.steps);
      } catch (err) {
        funnelDataRef.value = null;
        funnelError.value = err?.message || __("Failed to load funnel data.", "google-analytics-for-wordpress");
      } finally {
        funnelLoading.value = false;
      }
    }
    watch(
      () => [
        activeFunnel.value?.id,
        dateRange.value?.start,
        dateRange.value?.end,
        apiFilters.value
      ],
      () => loadFunnelData(),
      { immediate: true }
    );
    const fetchSavedFunnels = () => {
      const ajaxData = {
        action: "monsterinsights_overview_report_get_funnel_filters",
        nonce: getMiGlobal("nonce", "")
      };
      wp.ajax.post(ajaxData).done((response) => {
        overviewStore.setFunnels(response?.funnels || []);
      }).fail(() => {
        overviewStore.setFunnels([]);
      }).always(() => {
        funnelsInitializing.value = false;
      });
    };
    onMounted(() => {
      fetchSavedFunnels();
    });
    return (_ctx, _cache) => {
      return openBlock(), createBlock(ReportPageLayout, {
        "required-license": "pro",
        "upsell-feature": "ecommerce-funnel",
        "required-addon": "ecommerce"
      }, {
        table: withCtx(() => [
          createBaseVNode("div", _hoisted_1, [
            createBaseVNode("div", _hoisted_2, [
              createBaseVNode("span", _hoisted_3, toDisplayString(unref(__)("Funnel", "google-analytics-for-wordpress")), 1),
              withDirectives(createBaseVNode("select", {
                "onUpdate:modelValue": _cache[0] || (_cache[0] = ($event) => selectedFunnelId.value = $event),
                class: "monsterinsights-ecommerce-funnel-report__select",
                disabled: funnelLoading.value,
                onChange: onFunnelChange
              }, [
                createBaseVNode("option", _hoisted_5, toDisplayString(unref(__)("Choose Funnel", "google-analytics-for-wordpress")), 1),
                (openBlock(true), createElementBlock(Fragment, null, renderList(unref(overviewStore).funnels, (funnel) => {
                  return openBlock(), createElementBlock("option", {
                    key: funnel.id,
                    value: funnel.id
                  }, toDisplayString(funnel.name), 9, _hoisted_6);
                }), 128))
              ], 40, _hoisted_4), [
                [vModelSelect, selectedFunnelId.value]
              ])
            ]),
            createBaseVNode("button", {
              type: "button",
              class: "monsterinsights-ecommerce-funnel-report__manage-btn",
              onClick: openFunnelModal
            }, [
              createBaseVNode("span", null, toDisplayString(unref(__)("Manage Funnels", "google-analytics-for-wordpress")), 1),
              createVNode(Icon, {
                name: "settings",
                size: 16
              })
            ])
          ]),
          funnelLoading.value || funnelsInitializing.value ? (openBlock(), createElementBlock("div", _hoisted_7, [
            createVNode(LoadingSpinnerInline),
            createBaseVNode("span", null, toDisplayString(unref(__)("Loading funnel data...", "google-analytics-for-wordpress")), 1)
          ])) : funnelError.value ? (openBlock(), createElementBlock("div", _hoisted_8, [
            createBaseVNode("p", null, toDisplayString(funnelError.value), 1)
          ])) : activeFunnel.value && funnelSteps.value.length > 0 && funnelHasUsers.value ? (openBlock(), createElementBlock("div", _hoisted_9, [
            createBaseVNode("div", _hoisted_10, [
              createBaseVNode("div", _hoisted_11, toDisplayString(unref(__)("Conversion Rate By Stages", "google-analytics-for-wordpress")), 1),
              createBaseVNode("div", _hoisted_12, toDisplayString(unref(__)("Abandonment Rate", "google-analytics-for-wordpress")), 1)
            ]),
            createBaseVNode("div", _hoisted_13, [
              createBaseVNode("div", _hoisted_14, [
                (openBlock(true), createElementBlock(Fragment, null, renderList(funnelSteps.value, (step, idx) => {
                  return openBlock(), createElementBlock("div", {
                    key: "label-" + idx,
                    class: normalizeClass(["monsterinsights-report-ecommerce-funnel-box-body-col-1-item", { "monsterinsights-report-ecommerce-funnel-box-body-col-1-item-border": idx === 1 }])
                  }, toDisplayString(step.stepName), 3);
                }), 128))
              ]),
              createBaseVNode("div", _hoisted_15, [
                (openBlock(true), createElementBlock(Fragment, null, renderList(funnelSteps.value, (step, idx) => {
                  return openBlock(), createElementBlock("div", {
                    key: "bar-" + idx,
                    class: "monsterinsights-report-ecommerce-funnel-css-chart__step",
                    style: normalizeStyle(unref(getFunnelBarStyle)(step, idx, funnelSteps.value))
                  }, [
                    createBaseVNode("span", _hoisted_16, toDisplayString(step.activeUsers), 1)
                  ], 4);
                }), 128))
              ]),
              createBaseVNode("div", _hoisted_17, [
                (openBlock(true), createElementBlock(Fragment, null, renderList(funnelSteps.value, (step, idx) => {
                  return openBlock(), createElementBlock(Fragment, {
                    key: "comp-" + idx
                  }, [
                    idx < funnelSteps.value.length - 1 ? (openBlock(), createElementBlock("div", _hoisted_18, [
                      createBaseVNode("span", _hoisted_19, toDisplayString(step.completionRate.toFixed(1)) + "% ", 1),
                      _cache[1] || (_cache[1] = createBaseVNode("div", { class: "monsterinsights-report-ecommerce-funnel-box-body-col-3-item-inner-bg-border" }, null, -1))
                    ])) : createCommentVNode("", true)
                  ], 64);
                }), 128))
              ]),
              createBaseVNode("div", _hoisted_20, [
                (openBlock(true), createElementBlock(Fragment, null, renderList(funnelSteps.value, (step, idx) => {
                  return openBlock(), createElementBlock("div", {
                    key: "aband-" + idx,
                    class: normalizeClass(["monsterinsights-report-ecommerce-funnel-box-body-col-4-item", { "monsterinsights-report-ecommerce-funnel-box-body-col-4-item-border": idx === 1 }])
                  }, [
                    idx < funnelSteps.value.length - 1 ? (openBlock(), createElementBlock(Fragment, { key: 0 }, [
                      createTextVNode(toDisplayString(step.abandonmentRate.toFixed(1)) + "% ", 1)
                    ], 64)) : (openBlock(), createElementBlock("span", _hoisted_21, "--"))
                  ], 2);
                }), 128))
              ])
            ])
          ])) : createCommentVNode("", true),
          activeFunnel.value && funnelTableRows.value.length > 0 && funnelHasUsers.value ? (openBlock(), createElementBlock("div", _hoisted_22, [
            createBaseVNode("div", _hoisted_23, [
              createBaseVNode("h3", _hoisted_24, toDisplayString(unref(__)("Funnel Steps", "google-analytics-for-wordpress")), 1)
            ]),
            createBaseVNode("div", _hoisted_25, [
              createBaseVNode("table", _hoisted_26, [
                createBaseVNode("thead", null, [
                  createBaseVNode("tr", null, [
                    createBaseVNode("th", null, toDisplayString(unref(__)("Step", "google-analytics-for-wordpress")), 1),
                    createBaseVNode("th", null, toDisplayString(unref(__)("Users (% of Step 1)", "google-analytics-for-wordpress")), 1),
                    createBaseVNode("th", null, toDisplayString(unref(__)("Completion Rate", "google-analytics-for-wordpress")), 1),
                    createBaseVNode("th", null, toDisplayString(unref(__)("Abandonments", "google-analytics-for-wordpress")), 1),
                    createBaseVNode("th", null, toDisplayString(unref(__)("Abandonment Rate", "google-analytics-for-wordpress")), 1)
                  ])
                ]),
                createBaseVNode("tbody", null, [
                  (openBlock(true), createElementBlock(Fragment, null, renderList(funnelTableRows.value, (row, index) => {
                    return openBlock(), createElementBlock("tr", {
                      key: index,
                      style: normalizeStyle(getRowGradientStyle(row.barPercent))
                    }, [
                      createBaseVNode("td", null, toDisplayString(row.stepName), 1),
                      createBaseVNode("td", null, toDisplayString(row.activeUsers), 1),
                      createBaseVNode("td", null, toDisplayString(row.completionRate), 1),
                      createBaseVNode("td", null, toDisplayString(row.abandonments), 1),
                      createBaseVNode("td", null, toDisplayString(row.abandonmentRate), 1)
                    ], 4);
                  }), 128))
                ])
              ])
            ])
          ])) : activeFunnel.value && !funnelError.value && !funnelLoading.value && !funnelsInitializing.value ? (openBlock(), createElementBlock("div", _hoisted_27, [
            createBaseVNode("div", _hoisted_28, [
              createBaseVNode("h3", _hoisted_29, toDisplayString(emptyState.value.title), 1),
              createBaseVNode("p", _hoisted_30, toDisplayString(emptyState.value.message), 1),
              emptyState.value.hints.length ? (openBlock(), createElementBlock("ul", _hoisted_31, [
                (openBlock(true), createElementBlock(Fragment, null, renderList(emptyState.value.hints, (hint, idx) => {
                  return openBlock(), createElementBlock("li", {
                    key: "hint-" + idx
                  }, toDisplayString(hint), 1);
                }), 128))
              ])) : createCommentVNode("", true),
              emptyState.value.stepLabels.length ? (openBlock(), createElementBlock("div", _hoisted_32, [
                (openBlock(true), createElementBlock(Fragment, null, renderList(emptyState.value.stepLabels, (label, labelIdx) => {
                  return openBlock(), createElementBlock("code", {
                    key: "step-" + labelIdx
                  }, toDisplayString(label), 1);
                }), 128))
              ])) : createCommentVNode("", true),
              emptyState.value.showRecentRangeAction ? (openBlock(), createElementBlock("button", {
                key: 2,
                type: "button",
                class: "monsterinsights-button monsterinsights-button--primary monsterinsights-ecommerce-funnel-report__empty-action",
                onClick: applyLast7Days
              }, toDisplayString(unref(__)("Switch to Last 7 days", "google-analytics-for-wordpress")), 1)) : createCommentVNode("", true),
              emptyState.value.docUrl ? (openBlock(), createElementBlock("a", {
                key: 3,
                class: "monsterinsights-ecommerce-funnel-report__empty-doc",
                href: emptyState.value.docUrl,
                target: "_blank",
                rel: "noopener noreferrer"
              }, toDisplayString(unref(__)("How to set up eCommerce tracking", "google-analytics-for-wordpress")), 9, _hoisted_33)) : createCommentVNode("", true)
            ])
          ])) : !funnelError.value && !funnelLoading.value && !funnelsInitializing.value ? (openBlock(), createElementBlock("div", _hoisted_34, [
            createBaseVNode("p", null, toDisplayString(unref(__)("Select a funnel to view data.", "google-analytics-for-wordpress")), 1)
          ])) : createCommentVNode("", true),
          createVNode(_sfc_main$1, {
            "is-open": funnelModalOpen.value,
            onClose: closeFunnelModal
          }, null, 8, ["is-open"])
        ]),
        _: 1
      });
    };
  }
};
export {
  _sfc_main as default
};
