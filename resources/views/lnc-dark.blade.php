{{-- Light NC dark colours (follows the visitor's system setting). Included after the theme style so it wins. --}}
<style id="lnc-dark">
  @media (prefers-color-scheme: dark) {
    :root { color-scheme: dark; }
    /* the logo is black on transparent: keep it on a white chip */
    #logo { background: #FFFFFF; border-radius: 12px; padding: 8px 12px; box-sizing: border-box; }
    :root {
      --link-color: #FAD719;
      --link-color-hover: #FFE042;
      --link-color-active: #FFE042;
      --link-color-disabled: rgba(168, 168, 166, 0.5);
      --primary-button-background-color: #FAD719;
      --primary-button-text-color: #3F3F40;
      --primary-button-background-color-hover: #FFE042;
      --primary-button-text-color-hover: #3F3F40;
      --primary-button-background-color-active: #FFE042;
      --primary-button-text-color-active: #3F3F40;
      --primary-button-background-color-disabled: #2A2A2B;
      --primary-button-text-color-disabled: #6B6B6A;
      --secondary-button-background-color: #161615;
      --secondary-button-text-color: #E8E8E6;
      --secondary-button-background-color-hover: #1D1D1C;
      --secondary-button-text-color-hover: #E8E8E6;
      --secondary-button-background-color-active: #1D1D1C;
      --secondary-button-text-color-active: #E8E8E6;
      --secondary-button-background-color-disabled: #2A2A2B;
      --secondary-button-text-color-disabled: #6B6B6A;
      --uploader-background-color: rgba(22, 22, 21, 0.94);
      --uploader-text-color: #E8E8E6;
      --uploader-header-background-color: #1D1D1C;
      --uploader-header-text-color: #E8E8E6;
      --uploader-item-background-color: #161615;
      --uploader-item-text-color: #E8E8E6;
      --panel-background-color: #0A0A09;
      --panel-text-color: #E8E8E6;
      --panel-text-color-alt: #A8A8A6;
      --panel-item-background-color: #161615;
      --panel-item-text-color: #E8E8E6;
      --panel-item-background-color-hover: #1D1D1C;
      --panel-item-text-color-hover: #E8E8E6;
      --panel-item-action-hover-background-color-from: rgba(29, 29, 28, 0.4);
      --panel-item-action-hover-background-color-to: #1D1D1C;
      --panel-header-background-color: #FAD719;
      --panel-header-text-color: #3F3F40;
      --panel-subheader-background-color: #1D1D1C;
      --panel-subheader-text-color: #E8E8E6;
      --tabs-bar-background-color: #1D1D1C;
      --tabs-tab-background-color: #161615;
      --tabs-tab-text-color: #E8E8E6;
      --tabs-tab-background-color-hover: #4A4426;
      --tabs-tab-text-color-hover: #E8E8E6;
      --tabs-tab-background-color-active: #FAD719;
      --tabs-tab-text-color-active: #3F3F40;
      --panel-nav-item-background-color: #161615;
      --panel-nav-item-text-color: #E8E8E6;
      --panel-nav-item-background-color-hover: #4A4426;
      --panel-nav-item-text-color-hover: #E8E8E6;
      --panel-section-background-color: #161615;
      --panel-section-text-color: #E8E8E6;
      --panel-section-background-color-alt: #1D1D1C;
      --panel-section-text-color-alt: #E8E8E6;
      --table-background-color: #161615;
      --table-text-color: #E8E8E6;
      --table-header-background-color: #1D1D1C;
      --table-header-text-color: #E8E8E6;
      --table-row-background-color: #161615;
      --table-row-text-color: #E8E8E6;
      --table-row-background-color-alt: #1D1D1C;
      --table-row-text-color-alt: #E8E8E6;
      --input-background-color: #161615;
      --input-text-color: #E8E8E6;
      --input-border-color: #2A2A2B;
      --input-border-color-hover: #6B6B6A;
      --input-border-color-focus: #FAD719;
      --input-placeholder-color: #6B6B6A;
      --checkbox-background-color: #161615;
      --checkbox-checked-background-color: #FAD719;
      --checkbox-checked-check-color: #3F3F40;
      --label-text-color: #E8E8E6;
      --progress-bar-background-color: #2A2A2B;
      --progress-bar-fill-color: #FAD719;
      --progress-bar-text-color: #E8E8E6;
      --scrollbar-track-color: rgba(255, 255, 255, 0.06);
      --scrollbar-thumb-color: rgba(255, 255, 255, 0.25);
      --scrollbar-thumb-hover-color: rgba(255, 255, 255, 0.4);
      --overlay-background-color: rgba(0, 0, 0, 0.4);
      --color-success: #4ADE80;
      --color-warning: #FBBF24;
      --color-danger: #F87171;
    }
  }
</style>
