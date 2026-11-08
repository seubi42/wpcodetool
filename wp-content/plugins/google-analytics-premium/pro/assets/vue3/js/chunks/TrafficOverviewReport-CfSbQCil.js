import { a6 as storeToRefs, o as openBlock, G as createBlock, E as withCtx, b as createVNode, u as unref, p as computed, l as ref } from "./AppOverlays-Dpqzx_3P.js";
import { _ as __ } from "./default-i18n-KrIlCc2E.js";
import { u as useOverviewReportStore, A as isSampleDataEnabled, b as buildApiFilters } from "../reports-BheoY72J.js";
import { g as generateTrafficOverviewSample, f as fetchTrafficOverviewData } from "./trafficSampleData-SKa3Eohw.js";
import { a as formatCurr, f as formatPct, b as formatNum } from "./overviewTableFormatters-DLdHSY9J.js";
import { f as formatDateLabel } from "./useOverviewChartData-GhJP-XGY.js";
import { a as aggregateDateEntityRows } from "./aggregateDateEntityRows-i7QMgwng.js";
import { g as getCompareDateLabels } from "./compareDateLabels-B56Y3XjZ.js";
import { s as shouldHideNotSetValue } from "./reportValues-zrCA8aeQ.js";
import { u as useReportPermissions } from "./useReportPermissions-B-NHV_0o.js";
import { u as useReport } from "./useReport-DQyIb7bd.js";
import { R as ReportPageLayout } from "./ReportPageLayout-DqvrR5b3.js";
import { _ as _sfc_main$2 } from "./ReportChartSection-BazV7W0E.js";
import { _ as _sfc_main$1 } from "./ReportDataTable-D3EVrnGh.js";
import "./TheAppHeader-CJeDav-v.js";
import "./sanitize-CE5uy2g6.js";
import "./dateIntervals-BPoui_3H.js";
import "./addons-rxJejcjK.js";
import "./useNotices-CwGRydDs.js";
import "./Modal-D1CHxx1Y.js";
import "./Icon-BEJfUbqO.js";
import "./useAuthGate-CIPLEksz.js";
import "./flatpickr-Dd43BNxQ.js";
import "./globals-C78yxw47.js";
import "./UniversallyPromo-zip1hHZv.js";
import "./reportCache-BOWTHSjS.js";
import "./settings-WrluSu6E.js";
import "./ReAuthModal-BCbeLdss.js";
import "./auth-D7PLXWYn.js";
import "./ApexLineChart-DYQ0CoFj.js";
import "./vue3-apexcharts-DUo9cBii.js";
import "./useChartColors-DtUshN69.js";
import "./monsterinsights-style-D8E96NnX.js";
import "./LoadingSpinnerInline-DLpn9bnW.js";
import "./SiteNotes-BFaQMW0y.js";
import "./siteNotes-X1HDrUCZ.js";
import "./ReportTableModal-2-xrb7uz.js";
const _sfc_main = {
  __name: "TrafficOverviewReport",
  setup(__props) {
    const overviewStore = useOverviewReportStore();
    const { dateRange, activeFilters: storeActiveFilters, activeDevice: storeActiveDevice } = storeToRefs(overviewStore);
    const { isBlocked } = useReportPermissions({ minTier: "plus" });
    const activeChartTab = ref("sessions");
    const chartTabs = [
      { id: "sessions", label: __("Sessions", "google-analytics-for-wordpress"), icon: "users" },
      { id: "pageviews", label: __("Pageviews", "google-analytics-for-wordpress"), icon: "view" }
    ];
    const chartRawDates = computed(() => {
      const chartResult = rawData.value?.sessions_chart;
      if (!chartResult?.rows?.length) return [];
      return chartResult.rows.map((row) => row?.d?.[0] || "");
    });
    const isCompareActive = computed(
      () => !!(dateRange.value?.compareReport && dateRange.value?.compareStart && dateRange.value?.compareEnd)
    );
    const chartData = computed(() => {
      const chartResult = rawData.value?.sessions_chart;
      if (!chartResult?.rows?.length) return { categories: [], series: [] };
      const rows = chartResult.rows;
      const categories = [];
      const sessionsCurr = [];
      const pageViewsCurr = [];
      const sessionsPrev = [];
      const pageViewsPrev = [];
      const firstM = rows[0]?.m;
      const isCompareFormat = Array.isArray(firstM) && firstM.length === 2 && Array.isArray(firstM[0]) && firstM[0].length === 2 && isCompareActive.value;
      for (const row of rows) {
        const date = row?.d?.[0] || "";
        categories.push(formatDateLabel(date));
        if (isCompareFormat) {
          const mSessions = row?.m?.[0] || [];
          const mPageViews = row?.m?.[1] || [];
          sessionsPrev.push(Number(mSessions[0]) || 0);
          sessionsCurr.push(Number(mSessions[1]) || 0);
          pageViewsPrev.push(Number(mPageViews[0]) || 0);
          pageViewsCurr.push(Number(mPageViews[1]) || 0);
        } else {
          const m0 = Array.isArray(row?.m?.[0]) ? row.m[0] : [];
          sessionsCurr.push(Number(m0[0]) || 0);
          pageViewsCurr.push(Number(m0[1]) || 0);
        }
      }
      const primaryColor = "#3A93DD";
      const compareColor = "#A0AEC0";
      const isSessionsTab = activeChartTab.value === "sessions";
      const series = [];
      const colors = [];
      const strokeDashArray = [];
      series.push({
        name: isSessionsTab ? "Sessions" : "Pageviews",
        data: isSessionsTab ? sessionsCurr : pageViewsCurr
      });
      colors.push(primaryColor);
      strokeDashArray.push(0);
      if (isCompareFormat) {
        series.push({
          name: __("Previous Period", "google-analytics-for-wordpress"),
          data: isSessionsTab ? sessionsPrev : pageViewsPrev
        });
        colors.push(compareColor);
        strokeDashArray.push(5);
      }
      return { categories, series, colors, strokeDashArray };
    });
    const columns = [
      { key: "channel", label: __("Channel", "google-analytics-for-wordpress"), sortable: true },
      { key: "sessions", label: __("Sessions", "google-analytics-for-wordpress"), sortable: true },
      { key: "engagedSessions", label: __("Engaged Sessions", "google-analytics-for-wordpress"), sortable: true },
      { key: "pagesPerSession", label: __("Pages / Sessions", "google-analytics-for-wordpress"), sortable: true, totalType: "average" },
      { key: "purchases", label: __("Purchases", "google-analytics-for-wordpress"), sortable: true },
      { key: "conversionRate", label: __("Conversion Rate", "google-analytics-for-wordpress"), sortable: true, totalType: "average" },
      { key: "revenue", label: __("Revenue", "google-analytics-for-wordpress"), sortable: true }
    ];
    const aggregatedChannels = computed(
      () => aggregateDateEntityRows(rawData.value?.traffic_details?.rows, {
        metricCount: 6,
        avgIndices: [2],
        weightIndex: 0
      }).filter((entity) => !shouldHideNotSetValue(entity.dims?.[0]))
    );
    function formatChannelRow(dims, vals) {
      const name = dims[0] != null && String(dims[0]).trim() !== "" ? String(dims[0]) : __("(not set)", "google-analytics-for-wordpress");
      const sessions = vals[0] || 0;
      const purchases = vals[3] || 0;
      const conversionRate = sessions > 0 ? purchases / sessions * 100 : 0;
      return {
        channel: name,
        sessions: formatNum(sessions),
        engagedSessions: formatNum(vals[1] || 0),
        pagesPerSession: (vals[2] || 0).toFixed(2),
        purchases: formatNum(purchases),
        conversionRate: formatPct(conversionRate),
        revenue: formatCurr(vals[5] || 0)
      };
    }
    const tableRows = computed(
      () => aggregatedChannels.value.map((entity) => formatChannelRow(entity.dims, entity.current))
    );
    const compareRows = computed(
      () => aggregateDateEntityRows(rawData.value?.traffic_details_prev?.rows, {
        metricCount: 6,
        avgIndices: [2],
        weightIndex: 0
      }).map((entity) => formatChannelRow(entity.dims, entity.current))
    );
    const compareDateLabelsForTable = computed(() => getCompareDateLabels(dateRange.value));
    const { rawData, loading, error, reload } = useReport({
      fetch: () => fetchTrafficOverviewData(
        dateRange.value,
        buildApiFilters(storeActiveFilters.value, storeActiveDevice.value)
      ),
      sample: () => generateTrafficOverviewSample(dateRange.value),
      sampleWhen: () => isBlocked.value || isSampleDataEnabled(),
      isBlocked,
      watch: [dateRange, storeActiveFilters, storeActiveDevice],
      guard: () => !!(dateRange.value?.start && dateRange.value?.end)
    });
    return (_ctx, _cache) => {
      return openBlock(), createBlock(ReportPageLayout, {
        "required-license": "plus",
        "upsell-feature": "traffic-overview"
      }, {
        chart: withCtx(() => [
          createVNode(_sfc_main$2, {
            tabs: chartTabs,
            "active-tab": activeChartTab.value,
            "chart-data": chartData.value,
            loading: unref(loading),
            error: unref(error),
            "show-site-notes": !unref(isBlocked),
            "date-range": unref(overviewStore).dateRange,
            "raw-dates": chartRawDates.value,
            "onUpdate:activeTab": _cache[0] || (_cache[0] = ($event) => activeChartTab.value = $event),
            onSiteNotesSaved: unref(reload)
          }, null, 8, ["active-tab", "chart-data", "loading", "error", "show-site-notes", "date-range", "raw-dates", "onSiteNotesSaved"])
        ]),
        table: withCtx(() => [
          createVNode(_sfc_main$1, {
            title: unref(__)("Traffic Details", "google-analytics-for-wordpress"),
            columns,
            rows: tableRows.value,
            "compare-rows": compareRows.value,
            "compare-date-labels": compareDateLabelsForTable.value,
            loading: unref(loading),
            "empty-message": unref(__)("No data currently for the Traffic report.", "google-analytics-for-wordpress")
          }, null, 8, ["title", "rows", "compare-rows", "compare-date-labels", "loading", "empty-message"])
        ]),
        _: 1
      });
    };
  }
};
export {
  _sfc_main as default
};
