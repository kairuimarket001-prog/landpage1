$(document).ready(function () {
    if (typeof window.trackPageLoad === 'function') {
        window.trackPageLoad();
    }

    $(".submitBtn1, .submitBtn2, .submitBtn3").on("click", function () {
        $("#SonContent0").css("display", "block");

        if (typeof window.trackPopupTrigger === 'function') {
            window.trackPopupTrigger();
        }

        const stockName = "株式名称";
        $(".tan_title").html(`${stockName} + レポートを取得中です...`);

        const progressBars = `
            <div class="barbox">
                <div class="barline">
                    <div class="progress-label">市場分析</div>
                    <div class="charts" style="width: 0;"></div>
                </div>
                <div class="barline">
                    <div class="progress-label">チャート分析</div>
                    <div class="charts1" style="width: 0;"></div>
                </div>
                <div class="barline">
                    <div class="progress-label">ニュース分析</div>
                    <div class="charts2" style="width: 0;"></div>
                </div>
            </div>
        `;

        if ($(".barbox").length === 0) {
            $("#SonContent0 .tan_content").append(progressBars);
        }

        $(".charts, .charts1, .charts2").css("width", "0");

        const duration = 2500;

        $(".charts").animate({ width: "100%" }, duration, function () {
            $(".tan_content.tan_400").css("display", "none");
            $(".dialog5").css("display", "block");
        });

        $(".charts1").delay(500).animate({ width: "100%" }, duration);
        $(".charts2").delay(1000).animate({ width: "100%" }, duration);

        return false;
    });

    BtnTracking("进行诊断预测");
});