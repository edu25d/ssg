/**
 * public/assets/js/dashboard.js
 * 교사 메인 대시보드 클라이언트 스크립트 (반응형 & 스마트 학생 명단 등록기 포함)
 */

// --- [토스트 및 클립보드 복사] ---
function showToast(message) {
    const toast = document.getElementById('toast');
    if (!toast) return;
    toast.textContent = message;
    toast.className = "show";
    setTimeout(() => {
        toast.className = toast.className.replace("show", "");
    }, 2500);
}

function copyToClipboard(text, label) {
    navigator.clipboard.writeText(text).then(() => {
        showToast(`${label}이(가) 복사되었습니다.`);
    }).catch(err => {
        console.error('복사 실패:', err);
        showToast('복사에 실패했습니다.');
    });
}

// --- [키오스크 계정 관리 모달] ---
function openKioskEditModal(classId, className, teacherUsername) {
    const titleEl = document.getElementById('kioskModalTitle');
    if (titleEl) titleEl.innerText = `${className} - 입력기 ID 관리`;
    const addBtn = document.getElementById('kioskAddBtn');
    if (addBtn) {
        addBtn.onclick = function() { createKioskAccount(classId); };
    }
    const container = document.getElementById('kioskListContainer');
    if (!container) return;
    container.innerHTML = '<p style="text-align:center;">불러오는 중...</p>';
    document.getElementById('kioskEditModal').style.display = 'flex';

    fetch(`/api/classes/get_kiosk_accounts.php?class_id=${classId}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                if (data.accounts.length === 0) {
                    container.innerHTML = '<p style="text-align:center; color:#888;">생성된 입력기 계정이 없습니다.</p>';
                    return;
                }
                let html = '';
                data.accounts.forEach(acc => {
                    const prefix = teacherUsername ? `${teacherUsername}-` : '';
                    const suffix = prefix ? acc.kiosk_id.replace(new RegExp(`^${prefix}`), '') : acc.kiosk_id;
                    html += `
                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px; background:#f8f9fa; padding:6px 10px; border-radius:4px;">
                            <span style="font-weight:bold; font-family:monospace; color:#555; font-size:0.9rem; min-width:80px;">${prefix}</span>
                            <input type="text" value="${suffix}" id="kiosk_suffix_${acc.id}" style="width:100px; padding:4px 8px; border:1px solid #ccc; border-radius:4px; font-family:monospace;" oninput="this.value = this.value.replace(/[^a-zA-Z0-9]/g, '');">
                            <button type="button" class="btn btn-sm btn-success" onclick="updateKioskAccount(${acc.id})">저장</button>
                            <button type="button" class="btn btn-sm btn-danger" onclick="deleteKioskAccount(${acc.id}, ${classId}, '${teacherUsername}')">삭제</button>
                        </div>
                    `;
                });
                container.innerHTML = html;
            } else {
                container.innerHTML = `<p style="color:red;">오류: ${data.error}</p>`;
            }
        })
        .catch(() => {
            container.innerHTML = '<p style="color:red;">통신 오류가 발생했습니다.</p>';
        });
}

function updateKioskAccount(accountId) {
    const suffixInput = document.getElementById(`kiosk_suffix_${accountId}`);
    const suffix = suffixInput.value.trim();
    if (!suffix) {
        alert('식별자를 입력해 주세요.');
        return;
    }

    const formData = new URLSearchParams();
    formData.append('account_id', accountId);
    formData.append('suffix', suffix);

    fetch('/api/classes/update_kiosk_account.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast('입력기 ID가 변경되었습니다.');
        } else {
            alert('오류: ' + data.error);
        }
    })
    .catch(() => alert('통신 오류가 발생했습니다.'));
}

function deleteKioskAccount(accountId, classId, teacherUsername) {
    if (!confirm('이 입력기 계정을 삭제하시겠습니까?')) return;

    const formData = new URLSearchParams();
    formData.append('account_id', accountId);

    fetch('/api/classes/delete_kiosk_account.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast('삭제되었습니다.');
            openKioskEditModal(classId, '', teacherUsername);
        } else {
            alert('오류: ' + data.error);
        }
    })
    .catch(() => alert('통신 오류가 발생했습니다.'));
}

function createKioskAccount(classId) {
    const formData = new URLSearchParams();
    formData.append('class_id', classId);

    fetch('/api/classes/create_kiosk_account.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('오류: ' + data.error);
        }
    })
    .catch(err => {
        console.error(err);
        alert('통신 오류가 발생했습니다.');
    });
}

// --- [모달 공통 제어] ---
function openModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.style.display = 'flex';
}

function openAddModal() {
    document.getElementById('addClassModal').style.display = 'flex';
}

function openSingleEditModal(classId, className) {
    document.getElementById('editClassId').value = classId;
    document.getElementById('editClassName').value = className;
    document.getElementById('editClassModal').style.display = 'flex';
}

function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.style.display = 'none';
}

function deleteClass() {
    const classId = document.getElementById('editClassId').value;
    const className = document.getElementById('editClassName').value;
    if (!confirm(`[${className}] 학급을 정말로 삭제하시겠습니까?\n이 학급에 속한 모든 학생 및 시험 데이터가 비활성화됩니다.`)) return;

    const formData = new URLSearchParams();
    formData.append('class_id', classId);

    fetch('/api/classes/delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('삭제 오류: ' + (data.error || '알 수 없는 오류'));
        }
    })
    .catch(() => alert('서버 통신 오류가 발생했습니다.'));
}

// --- [구글 드라이브 폴더 선택 모달] ---
function openFolderModal() {
    const select = document.getElementById('folderSelect');
    select.innerHTML = '<option value="">폴더 목록을 불러오는 중...</option>';
    document.getElementById('folderModal').style.display = 'flex';

    fetch('/api/google/get_folders.php')
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                select.innerHTML = '<option value="">-- 이동할 폴더를 선택하세요 --</option>';
                data.folders.forEach(f => {
                    const opt = document.createElement('option');
                    opt.value = f.id;
                    opt.textContent = `📁 ${f.name}`;
                    opt.dataset.name = f.name;
                    select.appendChild(opt);
                });
            } else {
                select.innerHTML = `<option value="">오류: ${data.error || '폴더 목록 로드 실패'}</option>`;
            }
        })
        .catch(() => {
            select.innerHTML = '<option value="">통신 오류 발생</option>';
        });
}

const folderSelectEl = document.getElementById('folderSelect');
if (folderSelectEl) {
    folderSelectEl.addEventListener('change', function() {
        const selected = this.options[this.selectedIndex];
        const nameInput = document.getElementById('newFolderName');
        if (nameInput) nameInput.value = selected.dataset.name || '';
    });
}

// --- [복수 학급 선택 드롭다운] ---
function toggleClassDropdown() {
    const el = document.getElementById('classDropdown');
    if (el) el.classList.toggle('show');
}

window.addEventListener('click', function(e) {
    const container = document.querySelector('.multi-select-container');
    const dropdown = document.getElementById('classDropdown');
    if (container && dropdown && !container.contains(e.target)) {
        dropdown.classList.remove('show');
    }
});

function toggleAllClasses(master) {
    const checkboxes = document.querySelectorAll('.class-checkbox');
    checkboxes.forEach(cb => cb.checked = master.checked);
    updateSelectLabel();
}

function updateSelectLabel() {
    const checked = document.querySelectorAll('.class-checkbox:checked');
    const total = document.querySelectorAll('.class-checkbox').length;
    const label = document.getElementById('selectLabel');
    const master = document.getElementById('selectAllClasses');

    if (checked.length === 0) {
        label.innerText = '-- 학급 선택 --';
        if (master) { master.checked = false; master.indeterminate = false; }
    } else if (checked.length === total) {
        label.innerText = `전체 학급 (${checked.length}개)`;
        if (master) { master.checked = true; master.indeterminate = false; }
    } else {
        label.innerText = `${checked.length}개 학급 선택됨`;
        if (master) { master.checked = false; master.indeterminate = true; }
    }
}

// --- [결과 마감 및 복원] ---
function closeAssignment(assignId) {
    const msg = "정말로 이 평가를 마감하시겠습니까?\n\n마감 시 모든 채점 결과가 구글 시트로 백업되며, 서버에 저장된 채점 데이터와 이미지 원본은 완전히 파기됩니다.";
    if (!confirm(msg)) return;

    fetch('/api/google/sync_sheet.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ assign_id: assignId })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert("✅ " + (data.message || "마감 처리가 완료되었습니다."));
            location.reload();
        } else {
            alert("❌ 마감 오류: " + (data.message || data.error || "알 수 없는 오류가 발생했습니다."));
        }
    })
    .catch(err => {
        console.error(err);
        alert("서버와 통신하는 중 오류가 발생했습니다.");
    });
}

function restoreData(assignId) {
    if (!confirm("구글 시트에서 데이터를 복원하시겠습니까?")) return;

    fetch(`/api/google/restore_sheet.php?assign_id=${assignId}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert("✅ " + data.message);
                location.reload();
            } else {
                alert("❌ 복원 오류: " + data.error);
            }
        })
        .catch(err => {
            console.error(err);
            alert("서버 통신 중 오류가 발생했습니다.");
        });
}

// --- [QR 팝업 띄우기 (반응형 중앙)] ---
function openQrPopup(assignId) {
    const url = `/views/input/popup_qr.php?assign_id=${assignId}`;
    const width = 450;
    const height = 500;
    const left = Math.round((screen.width - width) / 2);
    const top = Math.round((screen.height - height) / 2);
    window.open(url, `QR_Popup_${assignId}`, `width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=no`);
}

function showQR(url, title) {
    const container = document.getElementById('qrcode-container');
    const titleEl = document.getElementById('qrModalTitle');
    if (titleEl) titleEl.innerText = title || '접속 QR 코드';
    if (!container) return;
    container.innerHTML = '';

    new QRCode(container, {
        text: url,
        width: 220,
        height: 220,
        colorDark: "#000000",
        colorLight: "#ffffff",
        correctLevel: QRCode.CorrectLevel.H
    });

    document.getElementById('qrModal').style.display = 'flex';
}

// --- [스마트 실시간 현황 경량 폴링 (JSON 3초 폴링)] ---
setInterval(() => {
    fetch('/api/classes/get_status.php')
        .then(res => res.json())
        .then(data => {
            if (data.success && Array.isArray(data.assignments)) {
                data.assignments.forEach(item => {
                    const assignId = item.assignment_id;
                    const subEls = document.querySelectorAll(`#submitted-count-${assignId}, .submitted-count-${assignId}`);
                    const pendEls = document.querySelectorAll(`#pending-count-${assignId}, .pending-count-${assignId}`);
                    
                    subEls.forEach(el => el.textContent = `${item.submitted_count}명`);
                    pendEls.forEach(el => {
                        el.textContent = `${item.pending_review_count}명`;
                        if (item.pending_review_count > 0) {
                            el.style.color = '#dc3545';
                            el.style.fontWeight = 'bold';
                        } else {
                            el.style.color = '#16a34a';
                        }
                    });
                });
            }
        })
        .catch(() => {});
}, 3000);


// =============================================================================
// =============================================================================
// [★ 신규 기능] 스마트 학생 명단 간편 관리 (2단 탭: 명단/PIN 관리 + 텍스트 일괄 등록)
// =============================================================================

let currentRosterClassId = 0;
let parsedStudentList = [];

function switchRosterTab(tabName) {
    const tabBtnList = document.getElementById('tabBtnList');
    const tabBtnEdit = document.getElementById('tabBtnEdit');
    const rosterTabList = document.getElementById('rosterTabList');
    const rosterTabEdit = document.getElementById('rosterTabEdit');

    if (tabName === 'list') {
        if (tabBtnList) tabBtnList.classList.add('active');
        if (tabBtnEdit) tabBtnEdit.classList.remove('active');
        if (rosterTabList) rosterTabList.classList.add('active');
        if (rosterTabEdit) rosterTabEdit.classList.remove('active');
    } else {
        if (tabBtnList) tabBtnList.classList.remove('active');
        if (tabBtnEdit) tabBtnEdit.classList.add('active');
        if (rosterTabList) rosterTabList.classList.remove('active');
        if (rosterTabEdit) rosterTabEdit.classList.add('active');
        parseRosterText();
    }
}

function openStudentRosterModal(classId, className) {
    currentRosterClassId = classId;
    parsedStudentList = [];

    const titleEl = document.getElementById('rosterModalTitle');
    if (titleEl) titleEl.innerText = `👥 [${className}] 학생 명단 간편 관리`;

    switchRosterTab('list');
    document.getElementById('studentRosterModal').style.display = 'flex';

    loadStudentRoster(classId);
}

function loadStudentRoster(classId) {
    const tbody = document.getElementById('studentManageTbody');
    const tabCount = document.getElementById('tabStudentCount');
    const textarea = document.getElementById('rosterTextarea');

    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:25px; color:#94a3b8;">학생 목록을 불러오는 중...</td></tr>';
    }

    fetch(`/api/classes/get_students.php?class_id=${classId}`)
        .then(res => res.json())
        .then(data => {
            if (data.success && Array.isArray(data.students)) {
                const list = data.students;
                if (tabCount) tabCount.textContent = list.length;

                if (list.length === 0) {
                    if (tbody) {
                        tbody.innerHTML = `
                            <tr>
                                <td colspan="4" style="text-align:center; padding:35px; color:#64748b;">
                                    <p style="margin-bottom:10px;">등록된 학생이 없습니다.</p>
                                    <button type="button" class="btn btn-sm btn-primary" onclick="switchRosterTab('edit')">
                                        ✍️ 명단 일괄 등록하기
                                    </button>
                                </td>
                            </tr>
                        `;
                    }
                    if (textarea) {
                        textarea.value = '';
                        textarea.placeholder = "여기에 엑셀이나 나이스에서 복사한 명단을 그대로 붙여넣으세요 (Ctrl+V)\n\n예시 1 (이름만):\n김철수\n이영희\n\n예시 2 (이름과 PIN):\n김철수\t1234\n이영희\t5678";
                    }
                    // 등록된 학생이 없으면 바로 텍스트 등록 탭으로 전환
                    switchRosterTab('edit');
                } else {
                    if (tbody) {
                        let html = '';
                        list.forEach((s, idx) => {
                            const isInit = parseInt(s.is_initial_pin) === 1;
                            const pinBadge = isInit
                                ? `<span class="badge" style="background:#fd7e14; color:#fff; font-size:0.75rem; padding:3px 6px; border-radius:4px;">초기 PIN(미변경)</span>`
                                : `<span class="badge" style="background:#198754; color:#fff; font-size:0.75rem; padding:3px 6px; border-radius:4px;">학생 변경 완료</span>`;

                            html += `
                                <tr>
                                    <td style="color:#64748b; font-weight:bold;">${idx + 1}</td>
                                    <td style="font-weight:700; color:#1e293b;">${escapeHtml(s.name)}</td>
                                    <td>${pinBadge}</td>
                                    <td style="text-align:center;">
                                        <button type="button" class="btn btn-sm btn-outline-danger" style="padding:2px 8px; font-size:0.8rem;" onclick="resetStudentPin(${s.id}, '${escapeHtml(s.name)}')">
                                            🔑 PIN 초기화
                                        </button>
                                    </td>
                                </tr>
                            `;
                        });
                        tbody.innerHTML = html;
                    }

                    // 텍스트 탭에도 기존 명단 동기화
                    if (textarea) {
                        const lines = list.map(s => (s.pin ? `${s.name}\t${s.pin}` : s.name));
                        textarea.value = lines.join('\n');
                    }
                }
                parseRosterText();
            } else {
                if (tbody) {
                    tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; padding:25px; color:#dc3545;">학생 목록 조회 실패: ${data.error || '오류 발생'}</td></tr>`;
                }
            }
        })
        .catch(() => {
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:25px; color:#dc3545;">통신 오류가 발생했습니다.</td></tr>';
            }
        });
}

function resetStudentPin(studentId, studentName) {
    const newPin = prompt(`[${studentName}] 학생의 새 4자리 비밀번호(PIN)를 입력하세요.\n(초기화 후 학생이 로그인할 때 이 번호를 입력하고 다시 본인 비밀번호를 설정하게 됩니다)`, '0000');
    if (newPin === null) return;

    const trimmed = newPin.trim();
    if (!/^\d{4}$/.test(trimmed)) {
        alert('비밀번호는 4자리 숫자여야 합니다.');
        return;
    }

    fetch('/api/classes/reset_student_pin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            student_id: studentId,
            new_pin: trimmed
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast('✅ ' + (data.message || '비밀번호가 초기화되었습니다.'));
            loadStudentRoster(currentRosterClassId);
        } else {
            alert('❌ 초기화 실패: ' + (data.error || data.message || '오류가 발생했습니다.'));
        }
    })
    .catch(() => alert('서버 통신 오류가 발생했습니다.'));
}

function parseRosterText() {
    const raw = document.getElementById('rosterTextarea')?.value || '';
    const defaultPin = (document.getElementById('defaultPinInput')?.value || '0000').trim();
    const warningEl = document.getElementById('rosterWarning');
    const tbody = document.getElementById('rosterPreviewTbody');
    const countBadge = document.getElementById('rosterCountBadge');
    const saveBtn = document.getElementById('rosterSaveBtn');

    if (!tbody || !countBadge) return;

    const lines = raw.split(/\r?\n/);
    parsedStudentList = [];
    const seenNames = new Map();
    let hasDuplicate = false;
    let dupName = '';

    lines.forEach((line) => {
        let trimmed = line.trim();
        if (!trimmed) return;

        // 앞쪽 번호 패턴 (예: "1.", "1 ", "1번 ", "01-") 제거
        trimmed = trimmed.replace(/^\d+[\.\s번\-]+/, '').trim();
        if (!trimmed) return;

        // 탭, 쉼표 등으로 분리
        const parts = trimmed.split(/[\t,]+/).map(p => p.trim()).filter(Boolean);
        let name = '';
        let pin = defaultPin;

        if (parts.length >= 2) {
            name = parts[0];
            pin = parts[1];
        } else {
            // 공백 분리 시도 (예: "김철수 1234")
            const spaceParts = trimmed.split(/\s+/);
            if (spaceParts.length >= 2 && /^\d{4}$/.test(spaceParts[spaceParts.length - 1])) {
                pin = spaceParts.pop();
                name = spaceParts.join(' ');
            } else {
                name = trimmed;
            }
        }

        name = name.trim();
        if (!name) return;

        // 중복 이름 체크
        if (seenNames.has(name)) {
            hasDuplicate = true;
            dupName = name;
        } else {
            seenNames.set(name, true);
        }

        parsedStudentList.push({ name, pin });
    });

    // 미리보기 렌더링
    tbody.innerHTML = '';
    parsedStudentList.forEach((s, idx) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td style="color:#64748b; font-weight:bold;">${idx + 1}</td>
            <td style="font-weight:700; color:#1e293b;">${escapeHtml(s.name)}</td>
            <td><code style="background:#f1f5f9; padding:2px 6px; border-radius:4px; font-weight:bold;">${escapeHtml(s.pin)}</code></td>
        `;
        tbody.appendChild(tr);
    });

    countBadge.textContent = `${parsedStudentList.length}명 인식됨`;

    if (warningEl && saveBtn) {
        if (hasDuplicate) {
            warningEl.textContent = `⚠️ 중복된 이름('${dupName}')이 있습니다. 학생 식별을 위해 '${dupName}1', '${dupName}2' 또는 '${dupName}A'로 고유하게 수정해주세요.`;
            warningEl.style.display = 'block';
            saveBtn.disabled = true;
        } else {
            warningEl.style.display = 'none';
            saveBtn.disabled = (parsedStudentList.length === 0);
        }
    }
}

function saveStudentRoster() {
    if (!currentRosterClassId || parsedStudentList.length === 0) {
        alert('저장할 학생 명단이 없습니다.');
        return;
    }

    const saveBtn = document.getElementById('rosterSaveBtn');
    saveBtn.disabled = true;
    saveBtn.innerText = '저장 및 동기화 중...';

    fetch('/api/classes/sync_students.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            class_id: currentRosterClassId,
            students: parsedStudentList
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast('✅ ' + (data.message || '학생 명단이 성공적으로 저장되었습니다.'));
            closeModal('studentRosterModal');
            setTimeout(() => location.reload(), 800);
        } else {
            alert('❌ 저장 실패: ' + (data.error || data.message || '오류가 발생했습니다.'));
            saveBtn.disabled = false;
            saveBtn.innerText = '학생 명단 일괄 저장';
        }
    })
    .catch(err => {
        console.error(err);
        alert('서버 통신 오류가 발생했습니다.');
        saveBtn.disabled = false;
        saveBtn.innerText = '학생 명단 일괄 저장';
    });
}

function escapeHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function onTemplateSelectChange(templateId) {
    const editBtn = document.getElementById('btnEditSelectedTemplate');
    const wrapper = document.getElementById('selectedTemplateEditWrapper');
    if (templateId) {
        if (editBtn) {
            editBtn.href = `/views/templates/editor.php?id=${templateId}`;
            editBtn.style.display = 'inline-flex';
        }
        if (wrapper) wrapper.style.display = 'block';
    } else {
        if (editBtn) {
            editBtn.style.display = 'none';
            editBtn.href = '#';
        }
        if (wrapper) wrapper.style.display = 'none';
    }
}

// [신규] 교사 메인 대시보드에서 학생 모범답안 공개 여부 원클릭 비동기 토글
function toggleDashboardAnswer(assignId, checkboxEl) {
    const isChecked = checkboxEl.checked;
    const parentLabel = checkboxEl.closest('label');
    
    fetch('/api/grading/toggle_answer_visibility.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            assignment_id: assignId,
            show_answer: isChecked ? 1 : 0
        })
    })
    .then(res => res.json())
    .then(data => {
        if (!data.success) {
            alert('설정 변경 실패: ' + (data.error || '오류 발생'));
            checkboxEl.checked = !isChecked;
        } else {
            showToast(data.message || (isChecked ? '💡 학생에게 모범 답안이 공개되었습니다.' : '🔒 학생 화면에서 모범 답안이 숨김 처리되었습니다.'));
            if (parentLabel) {
                parentLabel.style.borderColor = isChecked ? '#8b5cf6' : '#cbd5e1';
                parentLabel.style.color = isChecked ? '#6d28d9' : '#64748b';
            }
            // 데스크톱 / 모바일 체크박스 양방향 동기화
            const otherId = checkboxEl.id.startsWith('chkShowAnswerMobile') 
                ? `chkShowAnswer_${assignId}` 
                : `chkShowAnswerMobile_${assignId}`;
            const otherChk = document.getElementById(otherId);
            if (otherChk) {
                otherChk.checked = isChecked;
                const otherLabel = otherChk.closest('label');
                if (otherLabel) {
                    otherLabel.style.borderColor = isChecked ? '#8b5cf6' : '#cbd5e1';
                    otherLabel.style.color = isChecked ? '#6d28d9' : '#64748b';
                }
            }
        }
    })
    .catch(err => {
        console.error(err);
        alert('서버 통신 오류가 발생했습니다.');
        checkboxEl.checked = !isChecked;
    });
}

// [신규] 모바일 대시보드 세그먼트 탭 전환 함수
function switchMobileTab(tabName, btnEl) {
    document.querySelectorAll('.segment-tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-section').forEach(s => s.classList.remove('active'));

    if (btnEl) {
        btnEl.classList.add('active');
    }

    if (tabName === 'assignments') {
        const sec = document.getElementById('tabSectionAssignments');
        if (sec) sec.classList.add('active');
    } else if (tabName === 'classes') {
        const sec = document.getElementById('tabSectionClasses');
        if (sec) sec.classList.add('active');
    } else if (tabName === 'settings') {
        const sec = document.getElementById('tabSectionSettings');
        if (sec) sec.classList.add('active');
    }
}

// =============================================================================
// [★ 신규 기능] 학생 접속 및 개별 제출 전용 통합 공유 모달 (QR & 링크 복사)
// =============================================================================
let currentShareAssignId = 0;
let currentShareClassId = 0;
let currentShareUrl = '';
let currentShareLinkName = '접속 링크';

function openShareSheet(assignId, examTitle, className, shareUrl) {
    currentShareAssignId = assignId;
    currentShareClassId = 0;
    currentShareUrl = shareUrl;
    currentShareLinkName = '학생 개별 제출 링크';

    const titleEl = document.getElementById('shareModalExamTitle');
    const classEl = document.getElementById('shareModalClassTitle');
    if (titleEl) titleEl.innerText = `📱 ${examTitle} (${className})`;
    if (classEl) classEl.innerText = `학생이 각자의 기기로 직접 접속하여 답안지를 제출합니다.`;

    renderShareQr(shareUrl);
}

function openClassShareSheet(classId, className, shareUrl) {
    currentShareClassId = classId;
    currentShareAssignId = 0;
    currentShareUrl = shareUrl;
    currentShareLinkName = '내 공부방 접속 링크';

    const titleEl = document.getElementById('shareModalExamTitle');
    const classEl = document.getElementById('shareModalClassTitle');
    if (titleEl) titleEl.innerText = `🏫 ${className} 내 공부방 안내`;
    if (classEl) classEl.innerText = `학생들이 각자의 기기로 접속할 수 있는 학급 전용 포털입니다.`;

    renderShareQr(shareUrl);
}

function renderShareQr(url) {
    const qrContainer = document.getElementById('shareSheetQrcodeContainer');
    if (qrContainer) {
        qrContainer.innerHTML = '';
        new QRCode(qrContainer, {
            text: url,
            width: 170,
            height: 170,
            colorDark: "#0f172a",
            colorLight: "#ffffff",
            correctLevel: QRCode.CorrectLevel.M
        });
    }

    const modal = document.getElementById('studentShareModal');
    if (modal) {
        modal.style.display = 'flex';
    }
}

function copyCurrentShareLink() {
    if (currentShareUrl) {
        copyToClipboard(currentShareUrl, currentShareLinkName || '접속 링크');
        closeModal('studentShareModal');
    }
}

function openTvQrFromShare() {
    closeModal('studentShareModal');
    if (currentShareClassId) {
        openClassQrPopup(currentShareClassId);
    } else if (currentShareAssignId) {
        openQrPopup(currentShareAssignId);
    }
}

function openClassQrPopup(classId) {
    const url = `/views/input/popup_qr.php?class_id=${classId}`;
    const width = 520;
    const height = 620;
    const left = Math.round((screen.width - width) / 2);
    const top = Math.round((screen.height - height) / 2);
    window.open(url, `ClassQR_Popup_${classId}`, `width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=no`);
}


