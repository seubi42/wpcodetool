import { _ as __ } from "./default-i18n-KrIlCc2E.js";
import { A as isSampleDataEnabled } from "../reports-BheoY72J.js";
import { D as watch, z as onMounted, l as ref } from "./AppOverlays-Dpqzx_3P.js";
function useReport(options = {}) {
  const {
    fetch,
    watch: watchSources = [],
    sample = null,
    isBlocked = null,
    sampleWhen = null,
    transform = null,
    immediate = true,
    guard = null,
    beforeFetch = null
  } = options;
  const rawData = ref(null);
  const loading = ref(false);
  const error = ref(null);
  const isSample = ref(false);
  let loadId = 0;
  const shouldUseSample = () => sampleWhen ? !!sampleWhen() : !!(isBlocked?.value || isSampleDataEnabled());
  function loadSample() {
    if (!sample) {
      return;
    }
    const result = sample();
    rawData.value = transform ? transform(result) : result;
    isSample.value = true;
    loading.value = false;
    error.value = null;
  }
  async function reload() {
    if (guard && !guard()) {
      return;
    }
    if (shouldUseSample()) {
      ++loadId;
      loadSample();
      return;
    }
    if (beforeFetch && !beforeFetch()) {
      return;
    }
    const currentLoadId = ++loadId;
    loading.value = true;
    error.value = null;
    try {
      const response = await fetch();
      if (currentLoadId !== loadId) {
        return;
      }
      rawData.value = transform ? transform(response) : response;
      isSample.value = false;
    } catch (err) {
      if (currentLoadId !== loadId) {
        return;
      }
      error.value = err?.message || err?.title || __("Error loading report data.", "google-analytics-for-wordpress");
    } finally {
      if (currentLoadId === loadId) {
        loading.value = false;
      }
    }
  }
  if (watchSources.length > 0) {
    watch(watchSources, () => reload(), { deep: true });
  }
  if (immediate) {
    onMounted(() => reload());
  }
  return { rawData, loading, error, isSample, reload, loadSample };
}
export {
  useReport as u
};
