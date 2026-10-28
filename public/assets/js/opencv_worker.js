// WebAssembly(WASM) 기반 OpenCV.js 초기화 콜백 설정
self.Module = {
    onRuntimeInitialized: function() {
        console.log("🟢 OpenCV Worker 엔진 로딩 완료");
    }
};

// OpenCV.js 엔진 로드 (백그라운드에서 동기적으로 가져옴)
self.importScripts('https://docs.opencv.org/4.x/opencv.js');

// 메인 스레드(bulk_input.php)로부터 메시지를 받을 때 실행되는 리스너
self.onmessage = function(e) {
    const { studentIndex, pageIndex = 0, imageData } = e.data;

    // 만약 선생님이 페이지를 열자마자 너무 빨리 셔터를 눌러서 
    // 엔진이 미처 로드되기 전이라면 에러 반환
    if (typeof cv === 'undefined' || !cv.Mat) {
        self.postMessage({ studentIndex, pageIndex, error: "스캐너 엔진이 아직 준비되지 않았습니다. 1~2초 후 다시 시도해주세요." });
        return;
    }

    try {
        // 이미지 처리 로직 실행
        const resultImageData = processImage(imageData);
        
        // 처리가 끝난 최종 이미지 픽셀 데이터를 메인 스레드로 반환
        self.postMessage({ studentIndex, pageIndex, imageData: resultImageData });
    } catch (err) {
        self.postMessage({ studentIndex, pageIndex, error: err.message });
    }
};

// [핵심 크롭 로직] single_input.php의 warpPerspective 함수 이식
function processImage(imageData) {
    // Worker에서는 HTML <img>나 <canvas>를 쓸 수 없으므로,
    // 메인 스레드에서 넘겨받은 순수 픽셀 데이터(ImageData)를 OpenCV 행렬(Mat)로 변환합니다.
    let src = cv.matFromImageData(imageData);
    let dst = new cv.Mat();
    let gray = new cv.Mat();
    let blurred = new cv.Mat();
    let edged = new cv.Mat();

    // 목표 해상도 비율 설정 (A4/B4 비율인 1:1.414 적용)
    const targetW = Math.max(2000, imageData.width); 
    const targetH = Math.round(targetW * 1.414);

    // 1. 흑백 변환, 블러 처리, 엣지(윤곽선) 검출 
    cv.cvtColor(src, gray, cv.COLOR_RGBA2GRAY, 0);
    cv.GaussianBlur(gray, blurred, new cv.Size(5, 5), 0, 0, cv.BORDER_DEFAULT);
    cv.Canny(blurred, edged, 50, 200);

    // 2. 윤곽선 찾기
    let contours = new cv.MatVector();
    let hierarchy = new cv.Mat();
    cv.findContours(edged, contours, hierarchy, cv.RETR_EXTERNAL, cv.CHAIN_APPROX_SIMPLE);

    let maxArea = 0;
    let maxContour = null;

    // 3. 가장 큰 4각형(시험지 테두리) 찾기
    for (let i = 0; i < contours.size(); ++i) {
        let cnt = contours.get(i);
        let area = cv.contourArea(cnt);
        if (area > 20000) { // 노이즈(작은 면적) 무시
            let peri = cv.arcLength(cnt, true);
            let approx = new cv.Mat();
            cv.approxPolyDP(cnt, approx, 0.02 * peri, true);
            
            // 꼭짓점이 4개이고 가장 면적이 큰 것을 타겟으로 지정
            if (approx.rows === 4 && area > maxArea) {
                maxArea = area;
                if (maxContour) maxContour.delete();
                maxContour = approx;
            } else {
                approx.delete();
            }
        }
    }

    // 4. 모서리 4개를 기반으로 원근 보정(Perspective Transform) 수행
    if (maxContour) {
        let pts = [];
        for (let i = 0; i < 4; i++) {
            pts.push({ x: maxContour.data32S[i * 2], y: maxContour.data32S[i * 2 + 1] });
        }

        // y좌표 기준으로 상단/하단 분리 후, 각각 x좌표 기준으로 좌/우 정렬
        pts.sort((a, b) => a.y - b.y);
        let topTwo = pts.slice(0, 2).sort((a, b) => a.x - b.x);
        let bottomTwo = pts.slice(2, 4).sort((a, b) => a.x - b.x);

        let srcTri = cv.matFromArray(4, 1, cv.CV_32FC2, [
            topTwo[0].x, topTwo[0].y,
            topTwo[1].x, topTwo[1].y,
            bottomTwo[1].x, bottomTwo[1].y,
            bottomTwo[0].x, bottomTwo[0].y
        ]);

        let dstTri = cv.matFromArray(4, 1, cv.CV_32FC2, [
            0, 0,
            targetW - 1, 0,
            targetW - 1, targetH - 1,
            0, targetH - 1
        ]);

        let M = cv.getPerspectiveTransform(srcTri, dstTri);
        let dsize = new cv.Size(targetW, targetH);
        cv.warpPerspective(src, dst, M, dsize, cv.INTER_LINEAR, cv.BORDER_CONSTANT, new cv.Scalar());

        // 쓰임이 다한 좌표 변수 메모리 해제
        srcTri.delete(); dstTri.delete(); M.delete(); maxContour.delete();
    } else {
        // 시험지 테두리를 찾지 못한 경우: 단순히 크기만 조정하여 반환
        let dsize = new cv.Size(targetW, targetH);
        cv.resize(src, dst, dsize, 0, 0, cv.INTER_LINEAR);
    }

    // 5. 처리된 OpenCV 이미지 행렬(Mat)을 브라우저가 인식할 수 있는 ImageData 객체로 변환
    const resultImgData = new ImageData(new Uint8ClampedArray(dst.data), dst.cols, dst.rows);

    // [매우 중요] C++ 기반의 WebAssembly 메모리 누수 방지를 위한 수동 메모리 해제
    src.delete(); dst.delete(); gray.delete(); blurred.delete(); edged.delete(); contours.delete(); hierarchy.delete();

    return resultImgData;
}