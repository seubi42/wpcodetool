import { a6 as storeToRefs, o as openBlock, G as createBlock, E as withCtx, b as createVNode, u as unref, p as computed, l as ref } from "./AppOverlays-Dpqzx_3P.js";
import { _ as __ } from "./default-i18n-KrIlCc2E.js";
import { u as useOverviewReportStore, b as buildApiFilters } from "../reports-BheoY72J.js";
import { g as fetchRefundsData } from "./ecommerceReports-Ww85J4md.js";
import { u as useReportPermissions } from "./useReportPermissions-B-NHV_0o.js";
import { u as useReport } from "./useReport-DQyIb7bd.js";
import { h as generateRefundsSample } from "./ecommerceSampleData-CfcmM5cV.js";
import { f as formatPct, a as formatCurr, b as formatNum } from "./overviewTableFormatters-DLdHSY9J.js";
import { f as formatDateLabel } from "./useOverviewChartData-GhJP-XGY.js";
import { a as aggregateDateEntityRows } from "./aggregateDateEntityRows-i7QMgwng.js";
import { g as getCompareDateLabels } from "./compareDateLabels-B56Y3XjZ.js";
import { s as shouldHideNotSetValue } from "./reportValues-zrCA8aeQ.js";
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
  __name: "EcommerceRefundsReport",
  setup(__props) {
    const overviewStore = useOverviewReportStore();
    const { dateRange, activeFilters: storeActiveFilters, activeDevice: storeActiveDevice } = storeToRefs(overviewStore);
    const { isBlocked } = useReportPermissions({ minTier: "pro" });
    const activeChartTab = ref("refunds");
    const chartTabs = [
      { id: "refunds", label: __("Total Refunds", "google-analytics-for-wordpress"), icon: "undo" },
      { id: "amount", label: __("Refund Amount", "google-analytics-for-wordpress"), icon: "revenue" }
    ];
    const chartRawDates = computed(() => {
      const chartResult = rawData.value?.refunds_chart;
      if (!chartResult?.rows?.length) return [];
      return chartResult.rows.map((row) => row?.d?.[0] || "");
    });
    const isCompareActive = computed(
      () => !!(dateRange.value?.compareReport && dateRange.value?.compareStart && dateRange.value?.compareEnd)
    );
    function generateAllDates(start, end) {
      const dates = [];
      const s = new Date(start);
      const e = new Date(end);
      while (s <= e) {
        const y = s.getFullYear();
        const m = String(s.getMonth() + 1).padStart(2, "0");
        const d = String(s.getDate()).padStart(2, "0");
        dates.push(`${y}${m}${d}`);
        s.setDate(s.getDate() + 1);
      }
      return dates;
    }
    const chartData = computed(() => {
      const chartResult = rawData.value?.refunds_chart;
      const start = dateRange.value?.start;
      const end = dateRange.value?.end;
      if (!start || !end) return { categories: [], series: [] };
      const rows = Array.isArray(chartResult?.rows) ? chartResult.rows : [];
      const rowsByDate = {};
      for (const row of rows) {
        const d = row?.d?.[0];
        if (d) rowsByDate[d] = row;
      }
      const firstRow = rows[0];
      const firstM = firstRow?.m;
      const isCompareFormat = Array.isArray(firstM) && firstM.length === 2 && Array.isArray(firstM[0]) && firstM[0].length === 2 && isCompareActive.value;
      const allDates = generateAllDates(start, end);
      const categories = [];
      const refundCountCurr = [];
      const refundAmountCurr = [];
      const refundCountPrev = [];
      const refundAmountPrev = [];
      for (const date of allDates) {
        categories.push(formatDateLabel(date));
        const row = rowsByDate[date];
        if (row) {
          if (isCompareFormat) {
            const mCount = row?.m?.[0] || [];
            const mAmount = row?.m?.[1] || [];
            refundCountPrev.push(Number(mCount[0]) || 0);
            refundCountCurr.push(Number(mCount[1]) || 0);
            refundAmountPrev.push(Math.abs(Number(mAmount[0]) || 0));
            refundAmountCurr.push(Math.abs(Number(mAmount[1]) || 0));
          } else {
            const m0 = Array.isArray(row?.m?.[0]) ? row.m[0] : [];
            refundCountCurr.push(Number(m0[0]) || 0);
            refundAmountCurr.push(Math.abs(Number(m0[1]) || 0));
          }
        } else {
          refundCountCurr.push(0);
          refundAmountCurr.push(0);
          if (isCompareFormat) {
            refundCountPrev.push(0);
            refundAmountPrev.push(0);
          }
        }
      }
      const primaryColor = "#3A93DD";
      const compareColor = "#A0AEC0";
      const isRefundsTab = activeChartTab.value === "refunds";
      const series = [];
      const colors = [];
      const strokeDashArray = [];
      series.push({
        name: isRefundsTab ? "Total Refunds" : "Refund Amount",
        data: isRefundsTab ? refundCountCurr : refundAmountCurr
      });
      colors.push(primaryColor);
      strokeDashArray.push(0);
      if (isCompareFormat) {
        series.push({
          name: "Previous Period",
          data: isRefundsTab ? refundCountPrev : refundAmountPrev
        });
        colors.push(compareColor);
        strokeDashArray.push(5);
      }
      return { categories, series, colors, strokeDashArray };
    });
    const columns = [
      { key: "product", label: __("Product", "google-analytics-for-wordpress"), sortable: true },
      { key: "itemsSold", label: __("Items Sold", "google-analytics-for-wordpress"), sortable: true },
      { key: "refunds", label: __("Refunds", "google-analytics-for-wordpress"), sortable: true },
      { key: "pctRefunds", label: __("% of Total Refunds", "google-analytics-for-wordpress"), sortable: true, totalType: "average" },
      { key: "revenueRefunded", label: __("Revenue Refunded", "google-analytics-for-wordpress"), sortable: true },
      { key: "pctRefundedRevenue", label: __("% of Refunded Revenue", "google-analytics-for-wordpress"), sortable: true, totalType: "average" }
    ];
    const aggregatedRefunds = computed(
      () => aggregateDateEntityRows(rawData.value?.refunds_table?.rows, {
        metricCount: 3,
        avgIndices: []
      })
    );
    const refundedEntities = computed(
      () => aggregatedRefunds.value.filter((entity) => (entity.current?.[2] || 0) > 0).filter((entity) => !shouldHideNotSetValue(entity.dims[0]))
    );
    function formatRefundRow(dims, vals, totals) {
      const name = String(dims[0] || "").trim() || "(not set)";
      const refunds = vals[1] || 0;
      const refundAmount = vals[2] || 0;
      const pctRefunds = totals.refunds > 0 ? refunds / totals.refunds * 100 : 0;
      const pctRevenue = totals.amount > 0 ? refundAmount / totals.amount * 100 : 0;
      return {
        product: name,
        itemsSold: formatNum(vals[0] || 0),
        refunds: formatNum(refunds),
        pctRefunds: formatPct(pctRefunds),
        revenueRefunded: formatCurr(refundAmount),
        pctRefundedRevenue: formatPct(pctRevenue)
      };
    }
    function periodTotals(entities, select) {
      let refunds = 0;
      let amount = 0;
      for (const entity of entities) {
        const vals = select(entity);
        if (!vals) continue;
        refunds += vals[1] || 0;
        amount += vals[2] || 0;
      }
      return { refunds, amount };
    }
    const tableRows = computed(() => {
      const entities = refundedEntities.value;
      const totals = periodTotals(entities, (e) => e.current);
      return entities.map((entity) => formatRefundRow(entity.dims, entity.current, totals));
    });
    const compareRows = computed(() => {
      const entities = aggregateDateEntityRows(rawData.value?.refunds_table_prev?.rows, {
        metricCount: 3,
        avgIndices: []
      }).filter((entity) => (entity.current?.[2] || 0) > 0);
      if (entities.length === 0) return [];
      const totals = periodTotals(entities, (e) => e.current);
      return entities.map((entity) => formatRefundRow(entity.dims, entity.current, totals));
    });
    const compareDateLabelsForTable = computed(() => getCompareDateLabels(dateRange.value));
    const { rawData, loading, error, reload } = useReport({
      fetch: () => fetchRefundsData(
        dateRange.value,
        buildApiFilters(storeActiveFilters.value, storeActiveDevice.value)
      ),
      sample: () => generateRefundsSample(dateRange.value),
      isBlocked,
      watch: [dateRange, storeActiveFilters, storeActiveDevice],
      guard: () => !!(dateRange.value?.start && dateRange.value?.end)
    });
    return (_ctx, _cache) => {
      return openBlock(), createBlock(ReportPageLayout, {
        "required-license": "pro",
        "upsell-feature": "ecommerce-refunds",
        "required-addon": "ecommerce"
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
            title: unref(__)("Products Refunded", "google-analytics-for-wordpress"),
            columns,
            rows: tableRows.value,
            "compare-rows": compareRows.value,
            "compare-date-labels": compareDateLabelsForTable.value,
            loading: unref(loading),
            "empty-message": unref(__)("No refunds tracked during this time period.", "google-analytics-for-wordpress"),
            "required-addon": "ecommerce",
            "required-addon-name": "eCommerce"
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
