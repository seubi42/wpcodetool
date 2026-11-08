import { _ as __ } from "./default-i18n-KrIlCc2E.js";
import { m as defineStore, ab as getWidgetSupportForType } from "./AppOverlays-Dpqzx_3P.js";
import { d as getMiGlobal } from "./globals-C78yxw47.js";
function ajaxRequest(action, data = {}) {
  return new Promise((resolve, reject) => {
    const nonce = getMiGlobal("nonce");
    if (!window.wp || !window.wp.ajax) {
      reject(new Error("WordPress AJAX is not available"));
      return;
    }
    const requestData = {
      ...data,
      nonce
    };
    wp.ajax.post(action, requestData).done((response) => {
      resolve({ data: response });
    }).fail((error) => {
      reject(error);
    });
  });
}
const customDashboardAPI = {
  /**
   * Get all custom views for the current user
   * @returns {Promise<Array>} Array of custom view objects
   */
  async getCustomViews() {
    try {
      const response = await ajaxRequest("monsterinsights_get_custom_views", {});
      return response.data || [];
    } catch (error) {
      console.error("Error fetching custom views:", error);
      throw error;
    }
  },
  /**
   * Save a custom view (create or update)
   * @param {Object} viewData - The view data
   * @param {number} viewData.view_id - View ID (0 for new view)
   * @param {string} viewData.title - View title
   * @param {Array} viewData.layout - Widget layout configuration
   * @returns {Promise<Object>} Response with view_id and message
   */
  async saveCustomView(viewData) {
    try {
      const response = await ajaxRequest("monsterinsights_save_custom_view", {
        view_id: viewData.view_id || 0,
        title: viewData.title || "My Custom View",
        layout: JSON.stringify(viewData.layout || [])
      });
      return response.data;
    } catch (error) {
      console.error("Error saving custom view:", error);
      throw error;
    }
  },
  /**
   * Delete a custom view
   * @param {number} viewId - The view ID to delete
   * @returns {Promise<Object>} Response with success message
   */
  async deleteCustomView(viewId) {
    try {
      const response = await ajaxRequest("monsterinsights_delete_custom_view", {
        view_id: viewId
      });
      return response.data;
    } catch (error) {
      console.error("Error deleting custom view:", error);
      throw error;
    }
  },
  /**
   * Get widget metadata for available widgets
   * @returns {Promise<Object>} Object with widget metadata keyed by widget type
   */
  async getWidgetMetadata() {
    try {
      const response = await ajaxRequest(
        "monsterinsights_get_custom_view_metadata",
        {}
      );
      return response.data || {};
    } catch (error) {
      console.error("Error fetching widget metadata:", error);
      throw error;
    }
  },
  /**
   * Get dashboard data for specified widgets.
   *
   * Currently uses WordPress as a proxy to the Relay API.
   * A future enhancement will use direct browser-to-Relay communication
   * with the Bearer token for better performance.
   *
   * @param {Array} widgets - Array of widget configurations
   * @param {Object} dateRange - Date range with start and end dates
   * @returns {Promise<Object>} Dashboard data with widget results
   */
  async getDashboardData(widgets, dateRange) {
    try {
      const response = await ajaxRequest(
        "monsterinsights_get_custom_dashboard_data",
        {
          widgets: JSON.stringify(widgets),
          date_range: JSON.stringify(dateRange)
        }
      );
      return response.data;
    } catch (error) {
      console.error("Error fetching dashboard data:", error);
      throw error;
    }
  },
  /**
   * Update the order of views (drag-and-drop reordering)
   * @param {Array} viewOrders - Array of {id, order} objects
   * @returns {Promise<Object>} Response with success message
   */
  async updateViewsOrder(viewOrders) {
    try {
      const response = await ajaxRequest("monsterinsights_update_views_order", {
        view_orders: JSON.stringify(viewOrders)
      });
      return response.data;
    } catch (error) {
      console.error("Error updating view order:", error);
      throw error;
    }
  }
};
const useCustomViewsStore = defineStore("customViews", {
  state: () => ({
    // Views data
    views: [],
    currentView: null,
    // Widget metadata
    widgetMetadata: {},
    // Dashboard data
    dashboardData: {},
    // UI state
    loading: false,
    saving: false,
    error: null,
    // Date range
    dateRange: {
      start: "",
      end: ""
    }
  }),
  getters: {
    /**
     * Check if loading is in progress
     */
    isLoading: (state) => state.loading,
    /**
     * Get all views sorted by menu_order
     */
    allViews: (state) => {
      return [...state.views].sort((a, b) => {
        if (a.menu_order !== b.menu_order) {
          return a.menu_order - b.menu_order;
        }
        return b.id - a.id;
      });
    },
    /**
     * Get view by ID
     */
    getViewById: (state) => {
      return (id) => state.views.find((view) => view.id === parseInt(id, 10));
    },
    /**
     * Check if currently editing
     */
    isEditing: (state) => {
      return state.currentView && state.currentView.id > 0;
    },
    /**
     * Get widgets for current view
     */
    currentViewWidgets: (state) => {
      return state.currentView?.layout || [];
    },
    /**
     * Get widget metadata by type
     */
    getWidgetByType: (state) => {
      return (type) => state.widgetMetadata[type] || null;
    }
  },
  actions: {
    /**
     * Set loading state
     * @param {boolean} isLoading - Whether loading is in progress
     */
    setLoading(isLoading) {
      this.loading = isLoading;
    },
    /**
     * Load all custom views
     * Note: This method does NOT manage loading state - the calling component
     * should use setLoading() to control when loading starts/ends.
     */
    async loadViews() {
      this.error = null;
      try {
        const views = await customDashboardAPI.getCustomViews();
        this.views = views;
        return views;
      } catch (error) {
        this.error = {
          title: __("Error Loading Views", "google-analytics-for-wordpress"),
          message: error.message || __("Failed to load custom views", "google-analytics-for-wordpress")
        };
        throw error;
      }
    },
    /**
     * Load widget metadata
     */
    async loadWidgetMetadata() {
      try {
        const metadata = await customDashboardAPI.getWidgetMetadata();
        this.widgetMetadata = metadata;
        return metadata;
      } catch (error) {
        console.error("Failed to load widget metadata:", error);
        throw error;
      }
    },
    /**
     * Create a new view
     */
    createNewView(title = "") {
      this.currentView = {
        id: 0,
        title: title || __("New View", "google-analytics-for-wordpress"),
        layout: []
      };
    },
    /**
     * Create a new view from a template
     * @param {Object} template - Template object with layout
     * @returns {Promise<Object>} The created view
     */
    async createViewFromTemplate(template) {
      if (!template) {
        throw new Error(
          __("No template provided", "google-analytics-for-wordpress")
        );
      }
      if (Object.keys(this.widgetMetadata).length === 0) {
        await this.loadWidgetMetadata();
      }
      const title = template.newNavName || template.title || __("My New View", "google-analytics-for-wordpress");
      this.currentView = {
        id: 0,
        title,
        layout: []
      };
      if (!template.layout || template.layout.length === 0) {
        await this.saveCurrentView();
        return this.currentView;
      }
      const layout = [];
      template.layout.forEach((widgetConfig, index) => {
        const widgetMeta = this.widgetMetadata[widgetConfig.type];
        if (!widgetMeta) {
          console.warn(`Widget type ${widgetConfig.type} not found in metadata`);
          return;
        }
        const widgetId = `${widgetConfig.type}-${Date.now()}-${index}`;
        const defaults = { ...widgetMeta.defaults };
        if (defaults.type) {
          defaults.displayType = defaults.type;
          delete defaults.type;
        }
        const widget = {
          id: widgetId,
          type: widgetConfig.type,
          title: widgetMeta.title || widgetMeta.name,
          ...defaults,
          // Apply widget defaults
          position: {
            x: index % 3,
            // 3-column grid
            y: Math.floor(index / 3),
            w: widgetConfig.width || 1
          }
        };
        if (widgetConfig.displayType) {
          widget.displayType = widgetConfig.displayType;
        }
        layout.push(widget);
      });
      this.currentView.layout = layout;
      await this.saveCurrentView();
      return this.currentView;
    },
    /**
     * Load view for viewing (read-only mode)
     */
    async loadViewForViewing(viewId) {
      const view = this.getViewById(viewId);
      if (view) {
        this.currentView = { ...view };
      } else {
        await this.loadViews();
        const freshView = this.getViewById(viewId);
        if (freshView) {
          this.currentView = { ...freshView };
        } else {
          throw new Error(__("View not found", "google-analytics-for-wordpress"));
        }
      }
    },
    /**
     * Load view for editing
     */
    async loadViewForEdit(viewId) {
      const view = this.getViewById(viewId);
      if (view) {
        this.currentView = { ...view };
      } else {
        await this.loadViews();
        const freshView = this.getViewById(viewId);
        if (freshView) {
          this.currentView = { ...freshView };
        } else {
          throw new Error(__("View not found", "google-analytics-for-wordpress"));
        }
      }
      await this.refreshWidgetConfigs();
    },
    /**
     * Refresh widget configurations with latest backend metadata
     * This ensures widgets always use current backend defaults while preserving user choices
     */
    async refreshWidgetConfigs() {
      if (!this.currentView || !this.currentView.layout) {
        return;
      }
      if (Object.keys(this.widgetMetadata).length === 0) {
        await this.loadWidgetMetadata();
      }
      this.currentView.layout = this.currentView.layout.map((widget) => {
        const widgetMeta = this.widgetMetadata[widget.type];
        if (!widgetMeta) {
          return widget;
        }
        const refreshedDefaults = widgetMeta.defaults || {};
        const userChoices = {};
        if (widget.displayType && widget.displayType !== refreshedDefaults.type) {
          userChoices.displayType = widget.displayType;
        }
        if (widget.metrics && widget.metrics.length > 0) {
          const displayType = widget.displayType || refreshedDefaults.type || "infobox";
          const typeSupport = getWidgetSupportForType(
            widgetMeta.support,
            displayType
          );
          const supportedMetrics = typeSupport.metrics || [];
          const validUserMetrics = widget.metrics.filter(
            (m) => supportedMetrics.includes(m)
          );
          if (validUserMetrics.length > 0) {
            userChoices.metrics = validUserMetrics;
          }
        }
        const refreshedWidget = {
          ...refreshedDefaults,
          // Fresh defaults from backend
          ...userChoices,
          // User's valid choices
          id: widget.id,
          // Always keep ID
          type: widget.type,
          // Always keep type
          title: widget.title || widgetMeta.title,
          // Keep custom title or use widget default title
          position: widget.position
          // Always keep position
        };
        return refreshedWidget;
      });
    },
    /**
     * Save current view
     */
    async saveCurrentView() {
      if (!this.currentView) {
        throw new Error(__("No view to save", "google-analytics-for-wordpress"));
      }
      this.saving = true;
      this.error = null;
      try {
        const result = await customDashboardAPI.saveCustomView({
          view_id: this.currentView.id,
          title: this.currentView.title,
          layout: this.currentView.layout
        });
        if (result.view_id) {
          this.currentView.id = result.view_id;
        }
        await this.loadViews();
        return result;
      } catch (error) {
        this.error = {
          title: __("Error Saving View", "google-analytics-for-wordpress"),
          message: error.message || __("Failed to save custom view", "google-analytics-for-wordpress")
        };
        throw error;
      } finally {
        this.saving = false;
      }
    },
    /**
     * Delete a view
     */
    async deleteView(viewId) {
      try {
        const result = await customDashboardAPI.deleteCustomView(viewId);
        this.views = this.views.filter((view) => view.id !== viewId);
        if (this.currentView?.id === viewId) {
          this.currentView = null;
        }
        return result;
      } catch (error) {
        this.error = {
          title: __("Error Deleting View", "google-analytics-for-wordpress"),
          message: error.message || __("Failed to delete custom view", "google-analytics-for-wordpress")
        };
        throw error;
      }
    },
    /**
     * Add widget to current view
     */
    addWidget(widget, position = null) {
      if (!this.currentView) {
        return;
      }
      const newWidget = {
        id: `${widget.type}-${Date.now()}`,
        type: widget.type,
        ...widget.defaults,
        position: position || { x: 0, y: 0, w: 1, h: 1 }
      };
      this.currentView.layout.push(newWidget);
    },
    /**
     * Remove widget from current view
     */
    removeWidget(widgetId) {
      if (!this.currentView) {
        return;
      }
      this.currentView.layout = this.currentView.layout.filter(
        (widget) => widget.id !== widgetId
      );
    },
    /**
     * Update widget configuration
     */
    updateWidget(widgetId, updates) {
      if (!this.currentView) {
        return;
      }
      const index = this.currentView.layout.findIndex(
        (widget) => widget.id === widgetId
      );
      if (index !== -1) {
        this.currentView.layout[index] = {
          ...this.currentView.layout[index],
          ...updates
        };
      }
    },
    /**
     * Update widget positions (for drag and drop)
     */
    updateWidgetPositions(positions) {
      if (!this.currentView) {
        return;
      }
      positions.forEach(({ id, position }) => {
        const widget = this.currentView.layout.find((w) => w.id === id);
        if (widget) {
          widget.position = position;
        }
      });
    },
    /**
     * Load dashboard data for widgets
     */
    async loadDashboardData(widgets = null, dateRange = null) {
      const widgetsToLoad = widgets || this.currentViewWidgets;
      const range = dateRange || this.dateRange;
      if (!widgetsToLoad.length) {
        return;
      }
      this.loading = true;
      try {
        const data = await customDashboardAPI.getDashboardData(
          widgetsToLoad,
          range
        );
        this.dashboardData = data.widgets || {};
        if (data.date_range) {
          this.dateRange = data.date_range;
        }
        return data;
      } catch (error) {
        this.error = {
          title: __("Error Loading Data", "google-analytics-for-wordpress"),
          message: error.message || __("Failed to load dashboard data", "google-analytics-for-wordpress")
        };
        throw error;
      } finally {
        this.loading = false;
      }
    },
    /**
     * Set date range
     */
    setDateRange(start, end) {
      this.dateRange = { start, end };
    },
    /**
     * Update views order after drag-and-drop
     * @param {Array} viewOrders - Array of {id, order} objects
     */
    async updateViewsOrder(viewOrders) {
      try {
        await customDashboardAPI.updateViewsOrder(viewOrders);
        viewOrders.forEach(({ id, order }) => {
          const view = this.views.find((v) => v.id === id);
          if (view) {
            view.menu_order = order;
          }
        });
        return true;
      } catch (error) {
        this.error = {
          title: __("Error Updating Order", "google-analytics-for-wordpress"),
          message: error.message || __("Failed to update view order", "google-analytics-for-wordpress")
        };
        throw error;
      }
    },
    /**
     * Set error
     */
    setError(error) {
      this.error = error;
    },
    /**
     * Clear error
     */
    clearError() {
      this.error = null;
    },
    /**
     * Reset store
     */
    reset() {
      this.views = [];
      this.currentView = null;
      this.widgetMetadata = {};
      this.dashboardData = {};
      this.loading = false;
      this.saving = false;
      this.error = null;
      this.dateRange = { start: "", end: "" };
    }
  }
});
export {
  customDashboardAPI as c,
  useCustomViewsStore as u
};
