import { u as useAuthGate } from "./useAuthGate-CIPLEksz.js";
import { d as getMiGlobal, l as isPro, i as isAddonActive } from "./globals-C78yxw47.js";
import { p as computed } from "./AppOverlays-Dpqzx_3P.js";
const LICENSE_TIERS = ["lite", "basic", "plus", "pro", "elite", "agency", "master"];
function tierIndex(type) {
  return LICENSE_TIERS.indexOf("starter" === type ? "plus" : type);
}
function useReportPermissions(options = {}) {
  const minTier = String(options.minTier ?? "lite").toLowerCase();
  const { requiredAddon = "" } = options;
  const {
    isAuthenticated,
    showAuthModal,
    showReAuthModal,
    shouldBlurContent,
    openAuthModal,
    openReAuthModal,
    closeAuthModal
  } = useAuthGate();
  const canViewReports = computed(() => getMiGlobal("can_view_reports", true));
  const isPremium = computed(() => isPro());
  const licenseType = computed(() => {
    const license = getMiGlobal("license", {});
    if (license?.is_agency) {
      return "agency";
    }
    return license?.type || "lite";
  });
  const showUpsell = computed(() => {
    return licenseType.value === "basic";
  });
  const showUpsellPlus = computed(() => {
    const tier = licenseType.value;
    return tier === "basic" || tier === "plus" || tier === "starter";
  });
  const addonRequired = computed(() => {
    if (!requiredAddon) {
      return false;
    }
    return !isAddonActive(requiredAddon);
  });
  const needsAddonOnly = computed(() => {
    const userTierIndex = tierIndex(licenseType.value);
    const requiredTierIndex = tierIndex(minTier);
    if (userTierIndex < requiredTierIndex) {
      return false;
    }
    return addonRequired.value;
  });
  const isBlocked = computed(() => {
    const userTierIndex = tierIndex(licenseType.value);
    const requiredTierIndex = tierIndex(minTier);
    if (userTierIndex < requiredTierIndex) {
      return true;
    }
    if (addonRequired.value) {
      return true;
    }
    return false;
  });
  const needsReAuth = computed(() => {
    return canViewReports.value && isAuthenticated.value === false;
  });
  const shouldBlur = computed(() => shouldBlurContent.value);
  return {
    canViewReports,
    isAuthenticated,
    isPremium,
    licenseType,
    showUpsell,
    showUpsellPlus,
    addonRequired,
    needsAddonOnly,
    isBlocked,
    needsReAuth,
    shouldBlur,
    showAuthModal,
    showReAuthModal,
    openAuthModal,
    openReAuthModal,
    closeAuthModal
  };
}
export {
  useReportPermissions as u
};
