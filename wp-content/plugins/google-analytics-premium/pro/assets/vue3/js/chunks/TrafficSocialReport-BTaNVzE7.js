import { a6 as storeToRefs, o as openBlock, G as createBlock, E as withCtx, b as createVNode, u as unref, p as computed, l as ref } from "./AppOverlays-Dpqzx_3P.js";
import { _ as __ } from "./default-i18n-KrIlCc2E.js";
import { u as useOverviewReportStore, A as isSampleDataEnabled, b as buildApiFilters } from "../reports-BheoY72J.js";
import { k as generateTrafficSocialSample, l as fetchTrafficSocialData } from "./trafficSampleData-SKa3Eohw.js";
import { u as useReportPermissions } from "./useReportPermissions-B-NHV_0o.js";
import { u as useReport } from "./useReport-DQyIb7bd.js";
import { f as formatPct, a as formatCurr, b as formatNum } from "./overviewTableFormatters-DLdHSY9J.js";
import { f as formatDateLabel } from "./useOverviewChartData-GhJP-XGY.js";
import { a as aggregateDateEntityRows } from "./aggregateDateEntityRows-i7QMgwng.js";
import { g as getCompareDateLabels } from "./compareDateLabels-B56Y3XjZ.js";
import { d as getMiGlobal } from "./globals-C78yxw47.js";
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
import "./UniversallyPromo-zip1hHZv.js";
import "./reportCache-BOWTHSjS.js";
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
  __name: "TrafficSocialReport",
  setup(__props) {
    const overviewStore = useOverviewReportStore();
    const { dateRange, activeFilters: storeActiveFilters, activeDevice: storeActiveDevice } = storeToRefs(overviewStore);
    const { isBlocked } = useReportPermissions({ minTier: "plus" });
    const activeChartTab = ref("sessions");
    const chartTabs = [
      { id: "sessions", label: __("Sessions", "google-analytics-for-wordpress"), icon: "users" },
      { id: "pageviews", label: __("Pageviews", "google-analytics-for-wordpress"), icon: "view" }
    ];
    const SOCIAL_PLATFORMS = [
      "facebook",
      "instagram",
      "linkedin",
      "pinterest",
      "reddit",
      "tiktok",
      "x",
      "youtube",
      "threads",
      "whatsapp",
      "snapchat",
      "medium",
      "tumblr",
      "blogspot",
      "google",
      "wordpress",
      "feedspot",
      "email"
    ];
    function isSocialPlatform(networkName) {
      const name = (networkName || "").toLowerCase().replace(/[^a-z]/g, "");
      return SOCIAL_PLATFORMS.some((platform) => name.includes(platform));
    }
    function getSocialIcon(networkName) {
      const name = (networkName || "").toLowerCase().replace(/[^a-z]/g, "");
      const match = SOCIAL_PLATFORMS.find((icon) => name.includes(icon));
      if (!match) return "";
      const baseAssets = getMiGlobal("plugin_assets_url", "");
      if (!baseAssets) return "";
      return baseAssets + "images/social/icon-" + match + ".svg";
    }
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
          name: "Previous Period",
          data: isSessionsTab ? sessionsPrev : pageViewsPrev
        });
        colors.push(compareColor);
        strokeDashArray.push(5);
      }
      return { categories, series, colors, strokeDashArray };
    });
    const columns = [
      { key: "network", label: __("Network", "google-analytics-for-wordpress"), sortable: true, iconKey: "networkIcon" },
      { key: "sessions", label: __("Sessions", "google-analytics-for-wordpress"), sortable: true },
      { key: "engagedSessions", label: __("Engaged Sessions", "google-analytics-for-wordpress"), sortable: true },
      { key: "bounceRate", label: __("Bounce Rate", "google-analytics-for-wordpress"), sortable: true, totalType: "average" },
      { key: "purchases", label: __("Purchases", "google-analytics-for-wordpress"), sortable: true },
      { key: "revenue", label: __("Revenue", "google-analytics-for-wordpress"), sortable: true },
      { key: "conversionRate", label: __("Conversion Rate", "google-analytics-for-wordpress"), sortable: true, totalType: "average" }
    ];
    const aggregatedSocial = computed(
      () => aggregateDateEntityRows(rawData.value?.social_table?.rows, {
        metricCount: 6,
        avgIndices: [2, 5],
        weightIndex: 0
      }).filter((entity) => {
        const dimVal = entity.dims?.[0];
        return dimVal && isSocialPlatform(String(dimVal));
      })
    );
    function formatSocialRow(dims, vals) {
      const name = String(dims[0] ?? "").trim();
      return {
        network: name,
        networkIcon: getSocialIcon(name),
        sessions: formatNum(vals[0] || 0),
        engagedSessions: formatNum(vals[1] || 0),
        // API returns bounce rate as a decimal (0.05 = 5%)
        bounceRate: formatPct((vals[2] || 0) * 100),
        purchases: formatNum(vals[3] || 0),
        revenue: formatCurr(vals[4] || 0),
        // API returns session key event rate as a decimal (0.05 = 5%)
        conversionRate: formatPct((vals[5] || 0) * 100)
      };
    }
    const tableRows = computed(
      () => aggregatedSocial.value.map((entity) => formatSocialRow(entity.dims, entity.current))
    );
    const compareRows = computed(
      () => aggregateDateEntityRows(rawData.value?.social_table_prev?.rows, {
        metricCount: 6,
        avgIndices: [2, 5],
        weightIndex: 0
      }).map((entity) => formatSocialRow(entity.dims, entity.current))
    );
    const compareDateLabelsForTable = computed(() => getCompareDateLabels(dateRange.value));
    const { rawData, loading, error, reload } = useReport({
      fetch: () => fetchTrafficSocialData(
        dateRange.value,
        buildApiFilters(storeActiveFilters.value, storeActiveDevice.value)
      ),
      sample: () => generateTrafficSocialSample(dateRange.value),
      sampleWhen: () => isBlocked.value || isSampleDataEnabled(),
      isBlocked,
      watch: [dateRange, storeActiveFilters, storeActiveDevice],
      guard: () => !!(dateRange.value?.start && dateRange.value?.end)
    });
    return (_ctx, _cache) => {
      return openBlock(), createBlock(ReportPageLayout, {
        "required-license": "plus",
        "upsell-feature": "traffic-social"
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
            title: unref(__)("Social Networks", "google-analytics-for-wordpress"),
            columns,
            rows: tableRows.value,
            "compare-rows": compareRows.value,
            "compare-date-labels": compareDateLabelsForTable.value,
            loading: unref(loading),
            "empty-message": unref(__)("No data currently for the social media report.", "google-analytics-for-wordpress")
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
