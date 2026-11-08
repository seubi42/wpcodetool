import { m as defineStore, l as ref, p as computed, S as _export_sfc, aC as getUpgradeUrl, a6 as storeToRefs, z as onMounted, A as onBeforeUnmount, u as unref, o as openBlock, c as createElementBlock, a as createBaseVNode, t as toDisplayString, F as Fragment, h as renderList, b as createVNode, E as withCtx, v as createCommentVNode, k as normalizeClass, Q as isPro } from "./AppOverlays-Dpqzx_3P.js";
import { _ as _sfc_main$1 } from "./SettingsBlock-BINMceN_.js";
import { L as LoadingSpinnerInline } from "./LoadingSpinnerInline-DLpn9bnW.js";
import { m as miAjax } from "./useNotices-CwGRydDs.js";
import { a as useToast, d as useDialog } from "./addons-rxJejcjK.js";
import "./default-i18n-KrIlCc2E.js";
import "./sanitize-CE5uy2g6.js";
import "./Modal-D1CHxx1Y.js";
const { __ } = wp.i18n;
const useReportExportsStore = defineStore("reportExports", () => {
  const exports$1 = ref([]);
  const isLoading = ref(false);
  const error = ref(null);
  const lastRefresh = ref(null);
  const activeExportsCount = computed(() => {
    return exports$1.value.filter((e) => e.status === "pending" || e.status === "in_progress").length;
  });
  async function fetchExports() {
    isLoading.value = true;
    error.value = null;
    try {
      const data = await miAjax("monsterinsights_get_export_status");
      exports$1.value = data?.exports || [];
      lastRefresh.value = (/* @__PURE__ */ new Date()).toISOString();
    } catch (err) {
      error.value = err?.message || __("Failed to fetch exports", "google-analytics-premium");
      throw err;
    } finally {
      isLoading.value = false;
    }
  }
  async function createExport(payload) {
    const data = await miAjax("monsterinsights_export_report", {
      report_type: payload.report_type,
      start_date: payload.start_date,
      end_date: payload.end_date,
      format: payload.format
    });
    return { data };
  }
  async function deleteExport(exportId) {
    try {
      await miAjax("monsterinsights_delete_export", { export_id: exportId });
      exports$1.value = exports$1.value.filter((e) => e.id !== exportId);
    } catch (err) {
      error.value = err?.message || __("Failed to delete export", "google-analytics-premium");
      throw err;
    }
  }
  return {
    exports: exports$1,
    isLoading,
    error,
    lastRefresh,
    activeExportsCount,
    fetchExports,
    createExport,
    deleteExport
  };
});
const _hoisted_1 = {
  key: 0,
  class: "monsterinsights-tools-export-container monsterinsights-tools-export-lite"
};
const _hoisted_2 = { class: "monsterinsights-site-notes-category" };
const _hoisted_3 = { class: "monsterinsights-site-notes-upsell monsterinsights-bg-upsell-categories" };
const _hoisted_4 = { class: "monsterinsights-site-notes-upsell-window" };
const _hoisted_5 = ["textContent"];
const _hoisted_6 = ["innerHTML"];
const _hoisted_7 = { class: "monsterinsights-plus-list" };
const _hoisted_8 = ["textContent"];
const _hoisted_9 = ["href", "textContent"];
const _hoisted_10 = ["href", "textContent"];
const _hoisted_11 = {
  key: 1,
  class: "monsterinsights-tools-export-container"
};
const _hoisted_12 = { class: "monsterinsights-export-tools" };
const _hoisted_13 = { class: "monsterinsights-export-queue-section" };
const _hoisted_14 = { class: "monsterinsights-export-queue-header" };
const _hoisted_15 = ["textContent"];
const _hoisted_16 = { class: "monsterinsights-export-queue-actions" };
const _hoisted_17 = {
  key: 0,
  class: "monsterinsights-export-last-refresh"
};
const _hoisted_18 = ["disabled"];
const _hoisted_19 = ["textContent"];
const _hoisted_20 = {
  key: 0,
  class: "monsterinsights-export-loading"
};
const _hoisted_21 = ["textContent"];
const _hoisted_22 = {
  key: 1,
  class: "monsterinsights-export-empty"
};
const _hoisted_23 = ["textContent"];
const _hoisted_24 = ["textContent"];
const _hoisted_25 = {
  key: 2,
  class: "monsterinsights-export-table-wrapper"
};
const _hoisted_26 = { class: "monsterinsights-export-table" };
const _hoisted_27 = ["textContent"];
const _hoisted_28 = ["textContent"];
const _hoisted_29 = ["textContent"];
const _hoisted_30 = ["textContent"];
const _hoisted_31 = ["textContent"];
const _hoisted_32 = ["textContent"];
const _hoisted_33 = ["textContent"];
const _hoisted_34 = ["textContent"];
const _hoisted_35 = ["textContent"];
const _hoisted_36 = ["textContent"];
const _hoisted_37 = ["textContent"];
const _hoisted_38 = ["textContent"];
const _hoisted_39 = {
  key: 0,
  class: "monsterinsights-export-expires"
};
const _hoisted_40 = ["textContent"];
const _hoisted_41 = ["textContent"];
const _hoisted_42 = { key: 1 };
const _hoisted_43 = { class: "monsterinsights-export-actions" };
const _hoisted_44 = ["href"];
const _hoisted_45 = ["textContent"];
const _hoisted_46 = ["disabled", "onClick"];
const _hoisted_47 = ["textContent"];
const _sfc_main = {
  __name: "monsterinsights-ToolsTabReportExport",
  setup(__props) {
    const { __: __2, sprintf } = wp.i18n;
    const pro = isPro();
    const brandPro = "MonsterInsights Pro";
    const upgradeUrl = getUpgradeUrl("tools", "report-export");
    const texts = {
      sectionTitle: __2("Report Export", "google-analytics-premium"),
      title: sprintf(__2("Upgrade to %s", "google-analytics-premium"), brandPro),
      subtitle: sprintf(__2("Export your reports and access your full report export history with %s.", "google-analytics-premium"), brandPro),
      unlock: __2("Upgrade and Unlock", "google-analytics-premium"),
      viewAll: __2("View all Pro features", "google-analytics-premium")
    };
    const features = [
      __2("Export report data to CSV and XML", "google-analytics-premium"),
      __2("Access your report export queue history", "google-analytics-premium"),
      __2("Download completed exports anytime", "google-analytics-premium"),
      __2("Retry failed exports with one click", "google-analytics-premium")
    ];
    const exportsStore = useReportExportsStore();
    const { exports: exportItems, isLoading: isLoadingExports, lastRefresh, activeExportsCount } = storeToRefs(exportsStore);
    const isDeletingExport = ref(false);
    let refreshIntervalId = null;
    const text_title = __2("Report Export", "google-analytics-premium");
    const text_export_queue = __2("Export Queue", "google-analytics-premium");
    const text_last_updated = __2("Last updated: %s", "google-analytics-premium");
    const text_refresh = __2("Refresh", "google-analytics-premium");
    const text_loading = __2("Loading exports...", "google-analytics-premium");
    const text_no_exports = __2("No exports yet", "google-analytics-premium");
    const text_no_exports_hint = __2("Create your first export from the reports page.", "google-analytics-premium");
    const text_table_report = __2("Report", "google-analytics-premium");
    const text_table_date_range = __2("Date Range", "google-analytics-premium");
    const text_table_format = __2("Format", "google-analytics-premium");
    const text_table_status = __2("Status", "google-analytics-premium");
    const text_table_created = __2("Created", "google-analytics-premium");
    const text_table_expires = __2("Expires", "google-analytics-premium");
    const text_table_actions = __2("Actions", "google-analytics-premium");
    const text_download = __2("Download", "google-analytics-premium");
    const text_delete = __2("Delete", "google-analytics-premium");
    const text_expires_in_days = __2("%d days", "google-analytics-premium");
    const text_expires_soon = __2("Expires soon", "google-analytics-premium");
    const text_status_pending = __2("Pending", "google-analytics-premium");
    const text_status_in_progress = __2("In Progress", "google-analytics-premium");
    const text_status_complete = __2("Complete", "google-analytics-premium");
    const text_status_failed = __2("Failed", "google-analytics-premium");
    const { successToast } = useToast();
    const dialog = useDialog();
    const sortedExports = computed(() => {
      return [...exportItems.value].sort((a, b) => {
        const statusOrder = { in_progress: 0, pending: 1, complete: 2, failed: 3 };
        if (statusOrder[a.status] !== statusOrder[b.status]) {
          return statusOrder[a.status] - statusOrder[b.status];
        }
        return new Date(b.created) - new Date(a.created);
      });
    });
    function getStatusIcon(status) {
      const icons = {
        pending: "monstericon-clock",
        in_progress: "monstericon-arrows-spin",
        complete: "monstericon-check-circle",
        failed: "monstericon-times-circle"
      };
      return icons[status] || "monstericon-question";
    }
    function getStatusLabel(status) {
      const labels = {
        pending: text_status_pending,
        in_progress: text_status_in_progress,
        complete: text_status_complete,
        failed: text_status_failed
      };
      return labels[status] || status;
    }
    function formatDate(dateString) {
      if (!dateString) return "-";
      const date = new Date(dateString);
      return date.toLocaleDateString(void 0, {
        year: "numeric",
        month: "short",
        day: "numeric",
        hour: "2-digit",
        minute: "2-digit"
      });
    }
    function formatTimeAgo(date) {
      if (!date) return "";
      const seconds = Math.floor((/* @__PURE__ */ new Date() - new Date(date)) / 1e3);
      if (seconds < 60) return __2("just now", "google-analytics-premium");
      const minutes = Math.floor(seconds / 60);
      if (minutes < 60) return sprintf(__2("%d minute(s) ago", "google-analytics-premium"), minutes);
      const hours = Math.floor(minutes / 60);
      if (hours < 24) return sprintf(__2("%d hour(s) ago", "google-analytics-premium"), hours);
      const days = Math.floor(hours / 24);
      return sprintf(__2("%d day(s) ago", "google-analytics-premium"), days);
    }
    function handleRefresh() {
      exportsStore.fetchExports().catch(() => {
      });
    }
    async function handleDelete(exportItem) {
      const ok = await dialog.confirm({
        title: __2("Delete Export?", "google-analytics-premium"),
        message: __2("This action cannot be undone.", "google-analytics-premium"),
        confirmText: __2("Delete", "google-analytics-premium"),
        cancelText: __2("Cancel", "google-analytics-premium")
      });
      if (!ok) return;
      isDeletingExport.value = true;
      try {
        await exportsStore.deleteExport(exportItem.id);
        successToast({ title: __2("Export deleted successfully.", "google-analytics-premium") });
      } catch (error) {
        dialog.alert({
          variant: "error",
          title: __2("Delete Failed", "google-analytics-premium"),
          message: error?.message || __2("Failed to delete export.", "google-analytics-premium"),
          confirmText: __2("Ok", "google-analytics-premium")
        });
      } finally {
        isDeletingExport.value = false;
      }
    }
    function startAutoRefresh() {
      refreshIntervalId = setInterval(() => {
        if (activeExportsCount.value > 0) {
          exportsStore.fetchExports().catch(() => {
          });
        }
      }, 3e4);
    }
    function stopAutoRefresh() {
      if (refreshIntervalId) {
        clearInterval(refreshIntervalId);
        refreshIntervalId = null;
      }
    }
    onMounted(() => {
      exportsStore.fetchExports().catch(() => {
      });
      startAutoRefresh();
    });
    onBeforeUnmount(() => {
      stopAutoRefresh();
    });
    return (_ctx, _cache) => {
      return !unref(pro) ? (openBlock(), createElementBlock("div", _hoisted_1, [
        createBaseVNode("div", _hoisted_2, [
          createBaseVNode("div", _hoisted_3, [
            createBaseVNode("div", _hoisted_4, [
              createBaseVNode("h2", {
                textContent: toDisplayString(texts.title)
              }, null, 8, _hoisted_5),
              createBaseVNode("p", {
                innerHTML: texts.subtitle
              }, null, 8, _hoisted_6),
              createBaseVNode("ul", _hoisted_7, [
                (openBlock(), createElementBlock(Fragment, null, renderList(features, (feature, index) => {
                  return createBaseVNode("li", {
                    key: index,
                    textContent: toDisplayString(feature)
                  }, null, 8, _hoisted_8);
                }), 64))
              ]),
              createBaseVNode("a", {
                href: unref(upgradeUrl),
                target: "_blank",
                rel: "noopener",
                class: "monsterinsights-button monsterinsights-button-large",
                textContent: toDisplayString(texts.unlock)
              }, null, 8, _hoisted_9),
              _cache[0] || (_cache[0] = createBaseVNode("br", null, null, -1)),
              createBaseVNode("a", {
                href: unref(upgradeUrl),
                target: "_blank",
                rel: "noopener",
                class: "monsterinsights-button-text",
                textContent: toDisplayString(texts.viewAll)
              }, null, 8, _hoisted_10)
            ])
          ])
        ])
      ])) : (openBlock(), createElementBlock("div", _hoisted_11, [
        createVNode(_sfc_main$1, { title: unref(text_title) }, {
          default: withCtx(() => [
            createBaseVNode("div", _hoisted_12, [
              createBaseVNode("div", _hoisted_13, [
                createBaseVNode("div", _hoisted_14, [
                  createBaseVNode("h3", {
                    class: "monsterinsights-export-section-title",
                    textContent: toDisplayString(unref(text_export_queue))
                  }, null, 8, _hoisted_15),
                  createBaseVNode("div", _hoisted_16, [
                    unref(lastRefresh) ? (openBlock(), createElementBlock("span", _hoisted_17, toDisplayString(unref(sprintf)(unref(text_last_updated), formatTimeAgo(unref(lastRefresh)))), 1)) : createCommentVNode("", true),
                    createBaseVNode("button", {
                      type: "button",
                      class: "monsterinsights-button-text",
                      disabled: unref(isLoadingExports),
                      onClick: handleRefresh
                    }, [
                      createBaseVNode("span", {
                        textContent: toDisplayString(unref(text_refresh))
                      }, null, 8, _hoisted_19)
                    ], 8, _hoisted_18)
                  ])
                ]),
                unref(isLoadingExports) && unref(exportItems).length === 0 ? (openBlock(), createElementBlock("div", _hoisted_20, [
                  createVNode(LoadingSpinnerInline),
                  createBaseVNode("p", {
                    textContent: toDisplayString(unref(text_loading))
                  }, null, 8, _hoisted_21)
                ])) : unref(exportItems).length === 0 ? (openBlock(), createElementBlock("div", _hoisted_22, [
                  _cache[1] || (_cache[1] = createBaseVNode("i", { class: "monstericon-file-empty" }, null, -1)),
                  createBaseVNode("p", {
                    textContent: toDisplayString(unref(text_no_exports))
                  }, null, 8, _hoisted_23),
                  createBaseVNode("p", {
                    class: "monsterinsights-export-empty-hint",
                    textContent: toDisplayString(unref(text_no_exports_hint))
                  }, null, 8, _hoisted_24)
                ])) : (openBlock(), createElementBlock("div", _hoisted_25, [
                  createBaseVNode("table", _hoisted_26, [
                    createBaseVNode("thead", null, [
                      createBaseVNode("tr", null, [
                        createBaseVNode("th", {
                          textContent: toDisplayString(unref(text_table_report))
                        }, null, 8, _hoisted_27),
                        createBaseVNode("th", {
                          textContent: toDisplayString(unref(text_table_date_range))
                        }, null, 8, _hoisted_28),
                        createBaseVNode("th", {
                          textContent: toDisplayString(unref(text_table_format))
                        }, null, 8, _hoisted_29),
                        createBaseVNode("th", {
                          textContent: toDisplayString(unref(text_table_status))
                        }, null, 8, _hoisted_30),
                        createBaseVNode("th", {
                          textContent: toDisplayString(unref(text_table_created))
                        }, null, 8, _hoisted_31),
                        createBaseVNode("th", {
                          textContent: toDisplayString(unref(text_table_expires))
                        }, null, 8, _hoisted_32),
                        createBaseVNode("th", {
                          textContent: toDisplayString(unref(text_table_actions))
                        }, null, 8, _hoisted_33)
                      ])
                    ]),
                    createBaseVNode("tbody", null, [
                      (openBlock(true), createElementBlock(Fragment, null, renderList(sortedExports.value, (exportItem) => {
                        return openBlock(), createElementBlock("tr", {
                          key: exportItem.id
                        }, [
                          createBaseVNode("td", null, [
                            createBaseVNode("span", {
                              class: "monsterinsights-export-report-name",
                              textContent: toDisplayString(exportItem.report_name)
                            }, null, 8, _hoisted_34)
                          ]),
                          createBaseVNode("td", null, [
                            createBaseVNode("span", {
                              class: "monsterinsights-export-date-range",
                              textContent: toDisplayString(exportItem.date_range)
                            }, null, 8, _hoisted_35)
                          ]),
                          createBaseVNode("td", null, [
                            createBaseVNode("span", {
                              class: "monsterinsights-export-format",
                              textContent: toDisplayString((exportItem.format || "").toUpperCase())
                            }, null, 8, _hoisted_36)
                          ]),
                          createBaseVNode("td", null, [
                            createBaseVNode("span", {
                              class: normalizeClass(["monsterinsights-export-status", "monsterinsights-export-status-" + exportItem.status])
                            }, [
                              createBaseVNode("i", {
                                class: normalizeClass(getStatusIcon(exportItem.status))
                              }, null, 2),
                              createBaseVNode("span", {
                                textContent: toDisplayString(getStatusLabel(exportItem.status))
                              }, null, 8, _hoisted_37)
                            ], 2)
                          ]),
                          createBaseVNode("td", null, [
                            createBaseVNode("span", {
                              class: "monsterinsights-export-date",
                              textContent: toDisplayString(formatDate(exportItem.created))
                            }, null, 8, _hoisted_38)
                          ]),
                          createBaseVNode("td", null, [
                            exportItem.status === "complete" ? (openBlock(), createElementBlock("span", _hoisted_39, [
                              exportItem.expires_in > 0 ? (openBlock(), createElementBlock("span", {
                                key: 0,
                                textContent: toDisplayString(unref(sprintf)(unref(text_expires_in_days), exportItem.expires_in))
                              }, null, 8, _hoisted_40)) : (openBlock(), createElementBlock("span", {
                                key: 1,
                                class: "monsterinsights-export-expires-soon",
                                textContent: toDisplayString(unref(text_expires_soon))
                              }, null, 8, _hoisted_41))
                            ])) : (openBlock(), createElementBlock("span", _hoisted_42, "-"))
                          ]),
                          createBaseVNode("td", null, [
                            createBaseVNode("div", _hoisted_43, [
                              exportItem.status === "complete" && exportItem.download_url ? (openBlock(), createElementBlock("a", {
                                key: 0,
                                href: exportItem.download_url,
                                class: "monsterinsights-button monsterinsights-button-small monsterinsights-button-green",
                                target: "_blank"
                              }, [
                                _cache[2] || (_cache[2] = createBaseVNode("i", { class: "monstericon-download" }, null, -1)),
                                createBaseVNode("span", {
                                  class: "monsterinsights-export-action-button-text",
                                  textContent: toDisplayString(unref(text_download))
                                }, null, 8, _hoisted_45)
                              ], 8, _hoisted_44)) : createCommentVNode("", true),
                              createBaseVNode("button", {
                                type: "button",
                                class: "monsterinsights-button monsterinsights-button-small monsterinsights-button-red",
                                disabled: isDeletingExport.value,
                                onClick: ($event) => handleDelete(exportItem)
                              }, [
                                _cache[3] || (_cache[3] = createBaseVNode("i", { class: "monstericon-times-circle" }, null, -1)),
                                createBaseVNode("span", {
                                  class: "monsterinsights-export-action-button-text",
                                  textContent: toDisplayString(unref(text_delete))
                                }, null, 8, _hoisted_47)
                              ], 8, _hoisted_46)
                            ])
                          ])
                        ]);
                      }), 128))
                    ])
                  ])
                ]))
              ])
            ])
          ]),
          _: 1
        }, 8, ["title"])
      ]));
    };
  }
};
const monsterinsightsToolsTabReportExport = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-467037c7"]]);
export {
  monsterinsightsToolsTabReportExport as default
};
