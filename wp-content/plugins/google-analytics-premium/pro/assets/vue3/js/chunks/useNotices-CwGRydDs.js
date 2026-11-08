import { g as getMiGlobal, p as computed, M as reactive } from "./AppOverlays-Dpqzx_3P.js";
function getAjaxUrl() {
  return getMiGlobal("ajax") || window.ajaxurl;
}
function getNonce() {
  return getMiGlobal("nonce");
}
async function miAjax(action, data = {}, options = {}) {
  const params = new URLSearchParams();
  params.append("action", action);
  if (!("nonce" in data)) {
    params.append("nonce", getNonce());
  }
  for (const [key, value] of Object.entries(data)) {
    if (value !== void 0 && value !== null) {
      params.append(key, value);
    }
  }
  const response = await fetch(getAjaxUrl(), {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
    body: params.toString(),
    signal: options.signal
  });
  if (!response.ok) {
    throw { message: `${response.status} ${response.statusText}` };
  }
  const json = await response.json();
  if (json && json.success === false) {
    throw json.data || { message: "Request failed" };
  }
  if (json && json.success === true && typeof json.data !== "undefined") {
    return json.data;
  }
  return json;
}
async function ajaxPost(formData) {
  const response = await fetch(getAjaxUrl(), {
    method: "POST",
    body: formData
  });
  if (!response.ok) {
    throw new Error(`${response.status} ${response.statusText}`);
  }
  return response.json();
}
const state = reactive({
  notices: {}
});
function useNotices() {
  const allNotices = computed(() => state.notices);
  const hasNotices = computed(() => Object.keys(state.notices).length > 0);
  const addNotice = (noticeObject) => {
    state.notices = { ...state.notices, [noticeObject.id]: noticeObject };
  };
  const removeNotice = (noticeId) => {
    const { [noticeId]: _, ...rest } = state.notices;
    state.notices = rest;
  };
  const resetNotices = () => {
    state.notices = {};
  };
  const getNotice = (noticeId) => {
    return state.notices[noticeId] || null;
  };
  return {
    allNotices,
    hasNotices,
    addNotice,
    removeNotice,
    resetNotices,
    getNotice
  };
}
export {
  ajaxPost as a,
  getAjaxUrl as b,
  getNonce as g,
  miAjax as m,
  useNotices as u
};
