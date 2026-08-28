package com.binatechnologies.kgattendance;

import android.app.Activity;
import android.app.DownloadManager;
import android.content.Intent;
import android.graphics.Color;
import android.net.Uri;
import android.os.Bundle;
import android.os.Environment;
import android.webkit.CookieManager;
import android.webkit.MimeTypeMap;
import android.webkit.URLUtil;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceRequest;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.view.ViewGroup;
import android.widget.FrameLayout;
import android.widget.Toast;

import androidx.core.graphics.Insets;
import androidx.core.view.ViewCompat;
import androidx.core.view.WindowCompat;
import androidx.core.view.WindowInsetsCompat;
import androidx.core.view.WindowInsetsControllerCompat;

public class MainActivity extends Activity {
    private static final String START_URL = "https://kg.bina-technologies.com";
    private static final int FILE_CHOOSER_REQUEST = 1001;

    /** Matches the locked design system's navy header — the color visible behind the status bar. */
    private static final int STATUS_BAR_BACKDROP = Color.parseColor("#0F1F41");

    private WebView webView;
    private ValueCallback<Uri[]> filePathCallback;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        FrameLayout root = new FrameLayout(this);
        root.setBackgroundColor(STATUS_BAR_BACKDROP);

        webView = new WebView(this);
        root.addView(webView, new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
        setContentView(root);

        setUpEdgeToEdgeInsets(root);

        webView.getSettings().setJavaScriptEnabled(true);
        webView.getSettings().setDomStorageEnabled(true);
        webView.getSettings().setDatabaseEnabled(true);
        webView.getSettings().setAllowFileAccess(true);
        webView.getSettings().setAllowContentAccess(true);
        webView.getSettings().setSupportZoom(false);
        webView.getSettings().setBuiltInZoomControls(false);
        webView.getSettings().setDisplayZoomControls(false);

        CookieManager.getInstance().setAcceptCookie(true);
        CookieManager.getInstance().setAcceptThirdPartyCookies(webView, true);

        webView.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                Uri uri = request.getUrl();
                String host = uri.getHost();
                if (host != null && host.equalsIgnoreCase(Uri.parse(START_URL).getHost())) {
                    return false;
                }
                try {
                    startActivity(new Intent(Intent.ACTION_VIEW, uri));
                } catch (Exception e) {
                    Toast.makeText(MainActivity.this, "Unable to open this link", Toast.LENGTH_SHORT).show();
                }
                return true;
            }
        });

        webView.setDownloadListener(this::downloadFile);

        webView.setWebChromeClient(new WebChromeClient() {
            @Override
            public boolean onShowFileChooser(WebView webView, ValueCallback<Uri[]> callback, FileChooserParams params) {
                if (filePathCallback != null) {
                    filePathCallback.onReceiveValue(null);
                }
                filePathCallback = callback;
                try {
                    Intent intent = params.createIntent();
                    startActivityForResult(intent, FILE_CHOOSER_REQUEST);
                } catch (Exception e) {
                    filePathCallback = null;
                    Toast.makeText(MainActivity.this, "Unable to open file picker", Toast.LENGTH_SHORT).show();
                    return false;
                }
                return true;
            }
        });

        if (savedInstanceState == null) {
            webView.loadUrl(START_URL);
        } else {
            webView.restoreState(savedInstanceState);
        }
    }

    /**
     * The system now enforces edge-to-edge (this app targets SDK 35, where the OS draws
     * behind the status/navigation bars regardless of the theme's bar-color attributes),
     * so the WebView's own bounds cover the full screen including under the system bars.
     *
     * View.setPadding() on the WebView (the previous attempt, commit a28b3b6) does NOT
     * fix this: WebView padding only offsets where normal-flow content starts scrolling
     * from — it does not shrink the Chromium layout viewport, which is what CSS
     * `position: fixed` (used by this app's bottom nav — see bottom-nav.blade.php,
     * `fixed inset-x-0 bottom-0`) is positioned against. So a padded WebView still lets
     * fixed elements sit at the WebView's true, unshrunk edges — under the system bars.
     *
     * The fix here instead resizes the WebView's actual layout bounds: the WebView sits
     * in a FrameLayout, and system bar insets are applied as MARGINS on the WebView
     * itself. A margin genuinely changes the WebView's measured width/height, so
     * Chromium recomputes its CSS viewport to that smaller size — `position: fixed`
     * elements then anchor to the new, already-safe-area-constrained edges.
     */
    private void setUpEdgeToEdgeInsets(FrameLayout root) {
        WindowCompat.setDecorFitsSystemWindows(getWindow(), false);
        getWindow().setStatusBarColor(Color.TRANSPARENT);
        getWindow().setNavigationBarColor(Color.TRANSPARENT);

        // White (light) icons over the navy header; dark icons over the app's white
        // bottom navigation bar.
        WindowInsetsControllerCompat controller = WindowCompat.getInsetsController(getWindow(), webView);
        controller.setAppearanceLightStatusBars(false);
        controller.setAppearanceLightNavigationBars(true);

        ViewCompat.setOnApplyWindowInsetsListener(root, (view, insets) -> {
            Insets systemBars = insets.getInsets(WindowInsetsCompat.Type.systemBars());
            ViewGroup.MarginLayoutParams params = (ViewGroup.MarginLayoutParams) webView.getLayoutParams();
            params.topMargin = systemBars.top;
            params.bottomMargin = systemBars.bottom;
            params.leftMargin = systemBars.left;
            params.rightMargin = systemBars.right;
            webView.setLayoutParams(params);
            return WindowInsetsCompat.CONSUMED;
        });
    }

    /**
     * The web app's Export PDF / Export Excel buttons are ordinary GET links whose
     * response is Content-Disposition: attachment. Stock WebView silently drops such
     * responses unless a DownloadListener is registered — that's the entire reason the
     * buttons "did nothing." DownloadManager performs the actual write to the public
     * Downloads folder with its own system-level access, so no WRITE_EXTERNAL_STORAGE
     * (or any other storage) permission is needed on any supported API level here.
     */
    private void downloadFile(String url, String userAgent, String contentDisposition, String mimeType, long contentLength) {
        try {
            String cookies = CookieManager.getInstance().getCookie(url);
            String filename = URLUtil.guessFileName(url, contentDisposition, mimeType);

            DownloadManager.Request request = new DownloadManager.Request(Uri.parse(url));
            if (cookies != null) {
                request.addRequestHeader("Cookie", cookies);
            }
            request.addRequestHeader("User-Agent", userAgent);
            request.setMimeType(resolveMimeType(mimeType, filename));
            request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
            request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, filename);
            request.allowScanningByMediaScanner();

            DownloadManager downloadManager = (DownloadManager) getSystemService(DOWNLOAD_SERVICE);
            if (downloadManager != null) {
                downloadManager.enqueue(request);
                Toast.makeText(this, "Downloading " + filename, Toast.LENGTH_SHORT).show();
            }
        } catch (Exception e) {
            Toast.makeText(this, "Unable to download file", Toast.LENGTH_SHORT).show();
        }
    }

    private String resolveMimeType(String mimeType, String filename) {
        if (mimeType != null && !mimeType.isEmpty() && !mimeType.equals("application/octet-stream")) {
            return mimeType;
        }
        String extension = MimeTypeMap.getFileExtensionFromUrl(filename);
        String guessed = extension != null ? MimeTypeMap.getSingleton().getMimeTypeFromExtension(extension) : null;

        return guessed != null ? guessed : "application/octet-stream";
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode == FILE_CHOOSER_REQUEST && filePathCallback != null) {
            Uri[] results = WebChromeClient.FileChooserParams.parseResult(resultCode, data);
            filePathCallback.onReceiveValue(results);
            filePathCallback = null;
        }
    }

    @Override
    protected void onSaveInstanceState(Bundle outState) {
        webView.saveState(outState);
        super.onSaveInstanceState(outState);
    }

    @Override
    public void onBackPressed() {
        if (webView.canGoBack()) {
            webView.goBack();
        } else {
            super.onBackPressed();
        }
    }

    @Override
    protected void onDestroy() {
        if (filePathCallback != null) {
            filePathCallback.onReceiveValue(null);
            filePathCallback = null;
        }
        webView.destroy();
        super.onDestroy();
    }
}
