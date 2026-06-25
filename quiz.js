// ============================================
// PROQUIZ - COMPLETE JAVASCRIPT ENGINE
// ============================================

const SCRIPT_NAME = 'quiz.php';

// ---------- COMEDY MESSAGES ----------
const comedyMessages = [
    "😂 अरे भाई! 15 पॉइंट्स नहीं हैं तो हिंट कैसे लोगे? पहले सवाल तो सही करो!",
    "😅 ओहो! पॉइंट्स कम पड़ गए? लगता है आज किसी का दिन अच्छा नहीं है!",
    "🤣 'हिंट लेना है' - आपके पॉइंट्स: 'हम कहाँ जाएं?' पहले कमाओ फिर मांगो!",
    "😭 15 पॉइंट्स? आपके पास तो 0 हैं! ये तो 'चाय-पानी' वाली बात हो गई!",
    "🧠 हिंट की कीमत 15 पॉइंट्स है। आपके दिमाग की कीमत? फिलहाल 0!",
    "💀 'हिंट चाहिए' - आपके पॉइंट्स: 'हम सो रहे हैं'। पहले जगाओ हमें!",
    "🤡 आप: 'हिंट दो!' पॉइंट्स: 'हमें क्यों दें?' पहले कुछ सही करो फिर आना!",
    "😆 15 पॉइंट्स नहीं? तो फिर 'गूगल' कर लो! वो भी फ्री है!",
    "🤪 आपके पॉइंट्स इतने कम हैं कि हिंट भी आपको नहीं पहचान रहा!",
    "🥲 'हिंट माँग रहे हो?' - आपके पॉइंट्स: 'हम तो नए हैं यहाँ!'"
];

// ---------- STATE ----------
let questions = [];
let currentIndex = 0;
let selectedAnswers = [];
let lockedAnswers = [];
let hintUsed = [];
let points = 0;
let quizCompleted = false;
let timerInterval = null;
let timeLeft = 30;
const TIME_LIMIT = 30;
let quizStarted = false;
let questionStartTime = 0;
let timePerQuestion = [];
const HINT_COST = 15;
const POINTS_PER_CORRECT = 15;

// ---------- DOM REFS ----------
const setupPanel = document.getElementById('setupPanel');
const quizArea = document.getElementById('quizArea');
const resultPanel = document.getElementById('resultPanel');
const startQuizBtn = document.getElementById('startQuizBtn');
const difficultySelect = document.getElementById('difficultySelect');
const countSelect = document.getElementById('countSelect');
const shuffleSelect = document.getElementById('shuffleSelect');

const totalQ = document.getElementById('totalQ');
const correctCount = document.getElementById('correctCount');
const wrongCount = document.getElementById('wrongCount');
const accuracy = document.getElementById('accuracy');
const lockedCount = document.getElementById('lockedCount');
const unansweredCount = document.getElementById('unansweredCount');
const avgTime = document.getElementById('avgTime');
const pointsDisplay = document.getElementById('pointsDisplay');

const questionCounter = document.getElementById('questionCounter');
const timerDisplay = document.getElementById('timerDisplay');
const questionText = document.getElementById('questionText');
const optionsContainer = document.getElementById('optionsContainer');
const prevBtn = document.getElementById('prevBtn');
const nextBtn = document.getElementById('nextBtn');

const progressFill = document.getElementById('progressFill');
const progressLabel = document.getElementById('progressLabel');
const progressUnanswered = document.getElementById('progressUnanswered');

const answerDescription = document.getElementById('answerDescription');
const descriptionText = document.getElementById('descriptionText');
const hintBtn = document.getElementById('hintBtn');
const hintText = document.getElementById('hintText');
const hintContent = document.getElementById('hintContent');
const comedyMessage = document.getElementById('comedyMessage');
const comedyText = document.getElementById('comedyText');

const finalScore = document.getElementById('finalScore');
const resultBadge = document.getElementById('resultBadge');
const resultTime = document.getElementById('resultTime');
const resultPoints = document.getElementById('resultPoints');
const resultDetailGrid = document.getElementById('resultDetailGrid');
const restartBtn = document.getElementById('restartBtn');
const celebrationContainer = document.getElementById('celebrationContainer');

// History elements
const historyList = document.getElementById('historyList');
const historyToggle = document.getElementById('historyToggle');

// ---------- API FUNCTIONS ----------
async function fetchQuestions(difficulty, limit) {
    const url = `${SCRIPT_NAME}?api=1&action=get_questions&difficulty=${difficulty}&limit=${limit}`;
    console.log('🔄 Fetching questions from:', url);
    
    const response = await fetch(url);
    console.log('📡 Response status:', response.status);
    
    if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
    }
    
    const data = await response.json();
    console.log('📦 Response data:', data);
    return data;
}

async function saveSession(data) {
    const response = await fetch(`${SCRIPT_NAME}?api=1&action=save_session`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    });
    return await response.json();
}

async function fetchQuizHistory() {
    try {
        const response = await fetch(`${SCRIPT_NAME}?api=1&action=get_quiz_history&limit=20`);
        const data = await response.json();
        console.log('📦 History data:', data);
        return data;
    } catch(e) {
        console.error('Error fetching history:', e);
        return null;
    }
}

// ---------- HELPERS ----------
function shuffleArray(arr) {
    for (let i = arr.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [arr[i], arr[j]] = [arr[j], arr[i]];
    }
    return arr;
}

function shuffleOptions(q) {
    const opts = q.options.map((text, idx) => ({ text, idx }));
    const shuffled = shuffleArray([...opts]);
    const newOptions = shuffled.map(item => item.text);
    const correctMap = { 'A': 0, 'B': 1, 'C': 2, 'D': 3 };
    const correctIdx = correctMap[q.correct_answer] || 0;
    const shuffledCorrect = shuffled.findIndex(item => item.idx === correctIdx);
    return { ...q, options: newOptions, correct: shuffledCorrect };
}

function stopTimer() {
    if (timerInterval) {
        clearInterval(timerInterval);
        timerInterval = null;
    }
}

function startTimer() {
    stopTimer();
    timeLeft = TIME_LIMIT;
    timerDisplay.textContent = timeLeft;
    questionStartTime = Date.now();
    timerInterval = setInterval(() => {
        timeLeft--;
        timerDisplay.textContent = timeLeft >= 0 ? timeLeft : 0;
        if (timeLeft <= 0) {
            stopTimer();
            if (!quizCompleted && quizStarted) handleTimeout();
        }
    }, 1000);
}

function handleTimeout() {
    if (quizCompleted || !quizStarted) return;
    if (selectedAnswers[currentIndex] !== null && !lockedAnswers[currentIndex]) {
        lockedAnswers[currentIndex] = true;
        const elapsed = (Date.now() - questionStartTime) / 1000;
        timePerQuestion[currentIndex] = Math.min(elapsed, TIME_LIMIT);
    }
    const isLast = (currentIndex === questions.length - 1);
    if (isLast) {
        finishQuiz();
    } else {
        goToQuestion(currentIndex + 1);
    }
}

function triggerCelebration() {
    const colors = ['#ff6b6b', '#feca57', '#48dbfb', '#ff9ff3', '#54a0ff', '#5f27cd', '#1dd1a1', '#f368e0'];
    for (let i = 0; i < 80; i++) {
        const confetti = document.createElement('div');
        confetti.className = 'confetti';
        confetti.style.left = Math.random() * 100 + '%';
        confetti.style.background = colors[Math.floor(Math.random() * colors.length)];
        confetti.style.width = (Math.random() * 8 + 4) + 'px';
        confetti.style.height = (Math.random() * 8 + 4) + 'px';
        confetti.style.animationDuration = (Math.random() * 2 + 2) + 's';
        confetti.style.animationDelay = (Math.random() * 2) + 's';
        celebrationContainer.appendChild(confetti);
        setTimeout(() => confetti.remove(), 4000);
    }
}

// ---------- HISTORY FUNCTIONS ----------
async function loadHistory() {
    historyList.innerHTML = `
        <div class="history-loading">
            <i class="fas fa-spinner"></i>
            Loading history...
        </div>
    `;

    const result = await fetchQuizHistory();
    
    if (!result) {
        historyList.innerHTML = `
            <div class="history-empty">
                <i class="fas fa-exclamation-circle"></i>
                Could not load history. Please try again.
            </div>
        `;
        return;
    }

    if (!result.success) {
        historyList.innerHTML = `
            <div class="history-empty">
                <i class="fas fa-exclamation-circle"></i>
                Error: ${result.error || 'Unknown error'}
            </div>
        `;
        return;
    }

    if (result.history.length === 0) {
        historyList.innerHTML = `
            <div class="history-empty">
                <i class="fas fa-inbox"></i>
                No quiz history yet. Complete a quiz to see your results here!
            </div>
        `;
        return;
    }

    let html = '';
    result.history.forEach(session => {
        const total = session.total_questions;
        const correct = session.correct_answers;
        const wrong = session.wrong_answers;
        const pct = Math.round((correct / total) * 100);
        
        let badgeClass = 'poor';
        let badgeText = '📖 Practice More';
        if (pct >= 70) { badgeClass = 'good'; badgeText = '🌟 Good Job'; }
        else if (pct >= 50) { badgeClass = 'average'; badgeText = '📚 Keep Going'; }
        
        const date = new Date(session.completed_at);
        const formattedDate = date.toLocaleDateString('en-IN', { 
            day: '2-digit', 
            month: 'short', 
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
        
        html += `
            <div class="history-item">
                <div>
                    <span class="h-score">${correct}/${total}</span>
                    <span class="h-detail"> • ${pct}% • ${session.difficulty}</span>
                    <span class="h-detail"> • ⭐ ${session.points_earned} pts</span>
                </div>
                <div>
                    <span class="h-badge ${badgeClass}">${badgeText}</span>
                    <span class="h-date">${formattedDate}</span>
                </div>
            </div>
        `;
    });
    historyList.innerHTML = html;
}

// History toggle
historyToggle.addEventListener('click', function() {
    const isOpen = historyList.classList.toggle('show');
    this.innerHTML = isOpen ? 
        '<i class="fas fa-chevron-up"></i> Hide History' : 
        '<i class="fas fa-chevron-down"></i> Show History';
});

// ---------- BUILD QUIZ ----------
async function buildQuiz() {
    const diff = difficultySelect.value;
    const count = parseInt(countSelect.value);
    const shouldShuffle = shuffleSelect.value === 'true';

    startQuizBtn.disabled = true;
    startQuizBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';

    try {
        const result = await fetchQuestions(diff, count);
        
        if (!result.success) {
            alert('Error: ' + (result.message || result.error || 'Unknown error'));
            startQuizBtn.disabled = false;
            startQuizBtn.innerHTML = '<i class="fas fa-play"></i> Start Quiz';
            return;
        }

        if (!result.questions || result.questions.length === 0) {
            alert('No questions found in the database!');
            startQuizBtn.disabled = false;
            startQuizBtn.innerHTML = '<i class="fas fa-play"></i> Start Quiz';
            return;
        }

        console.log('✅ Received ' + result.questions.length + ' questions');

        let rawQuestions = result.questions;
        let shuffled = shuffleArray([...rawQuestions]);
        let selected = shuffled.slice(0, Math.min(count, shuffled.length));

        questions = selected.map(q => shuffleOptions(q));

        if (shouldShuffle) {
            questions = shuffleArray(questions);
        }

        selectedAnswers = new Array(questions.length).fill(null);
        lockedAnswers = new Array(questions.length).fill(false);
        hintUsed = new Array(questions.length).fill(false);
        timePerQuestion = new Array(questions.length).fill(0);
        points = 0;
        currentIndex = 0;
        quizCompleted = false;
        quizStarted = true;
        
        setupPanel.classList.add('hidden');
        quizArea.classList.remove('hidden');
        resultPanel.classList.add('hidden');
        answerDescription.classList.remove('show');
        hintText.classList.remove('show');
        comedyMessage.classList.remove('show');
        
        updatePointsDisplay();
        renderQuestion(0);
        updateDashboard();
        updateProgress();
    } catch (error) {
        console.error('Error:', error);
        alert('Error loading questions: ' + error.message);
    } finally {
        startQuizBtn.disabled = false;
        startQuizBtn.innerHTML = '<i class="fas fa-play"></i> Start Quiz';
    }
}

// ---------- RENDER QUESTION ----------
function renderQuestion(index) {
    if (!quizStarted || quizCompleted || !questions.length) return;
    const q = questions[index];
    questionText.textContent = q.question;
    questionCounter.textContent = `Q${index+1} / ${questions.length}`;

    answerDescription.classList.remove('show');
    comedyMessage.classList.remove('show');

    if (hintUsed[index]) {
        hintContent.textContent = q.hint || 'No hint available.';
        hintText.classList.add('show');
        hintBtn.disabled = true;
    } else {
        hintText.classList.remove('show');
        hintBtn.disabled = false;
        hintBtn.innerHTML = `<i class="fas fa-lightbulb"></i> Hint (${HINT_COST} pts)`;
    }

    const letters = ['A', 'B', 'C', 'D'];
    const selectedIdx = selectedAnswers[index];
    const isLocked = lockedAnswers[index];
    let html = '';
    for (let i = 0; i < q.options.length; i++) {
        let cls = 'option';
        if (selectedIdx === i) cls += ' selected';
        if (isLocked) cls += ' locked disabled';
        if (quizCompleted) cls += ' disabled';
        const lockIcon = isLocked && selectedIdx === i ?
            '<i class="fas fa-lock" style="margin-left:auto; color:#267b8b;"></i>' : '';
        html += `
            <div class="${cls}" data-opt-index="${i}" data-q-index="${index}">
                <span class="opt-letter">${letters[i]}</span>
                <span>${q.options[i]}</span>
                ${lockIcon}
            </div>
        `;
    }
    optionsContainer.innerHTML = html;

    if (!quizCompleted && quizStarted) {
        document.querySelectorAll('.option:not(.locked)').forEach(opt => {
            opt.addEventListener('click', onOptionClick);
        });
    }

    prevBtn.disabled = (index === 0);
    const isLast = (index === questions.length - 1);
    nextBtn.textContent = isLast ? 'Finish' : 'Next';
    nextBtn.disabled = false;

    updateDashboard();
    updateProgress();
    startTimer();
}

function onOptionClick(e) {
    if (quizCompleted || !quizStarted) return;
    const div = e.currentTarget;
    const qIdx = parseInt(div.dataset.qIndex);
    const optIdx = parseInt(div.dataset.optIndex);
    if (qIdx !== currentIndex) return;
    if (lockedAnswers[currentIndex]) return;

    const elapsed = (Date.now() - questionStartTime) / 1000;
    timePerQuestion[currentIndex] = Math.min(elapsed, TIME_LIMIT);

    selectedAnswers[currentIndex] = optIdx;
    lockedAnswers[currentIndex] = true;

    const q = questions[currentIndex];
    if (optIdx === q.correct) {
        points += POINTS_PER_CORRECT;
        updatePointsDisplay();
        answerDescription.classList.remove('show');
    } else {
        descriptionText.textContent = q.description || 'No description available.';
        answerDescription.classList.add('show');
    }

    renderQuestion(currentIndex);
    updateDashboard();
    updateProgress();
}

// ---------- HINT BUTTON ----------
hintBtn.addEventListener('click', function() {
    if (quizCompleted || !quizStarted) return;
    if (hintUsed[currentIndex]) return;

    if (points >= HINT_COST) {
        points -= HINT_COST;
        updatePointsDisplay();
        hintUsed[currentIndex] = true;
        const q = questions[currentIndex];
        hintContent.textContent = q.hint || 'No hint available.';
        hintText.classList.add('show');
        hintBtn.disabled = true;
        comedyMessage.classList.remove('show');
    } else {
        const randomMsg = comedyMessages[Math.floor(Math.random() * comedyMessages.length)];
        comedyText.textContent = randomMsg;
        comedyMessage.classList.add('show');
        setTimeout(() => {
            comedyMessage.classList.remove('show');
        }, 7000);
    }
});

function goToQuestion(index) {
    if (quizCompleted || !quizStarted) return;
    if (index < 0 || index >= questions.length) return;
    if (selectedAnswers[currentIndex] !== null && !lockedAnswers[currentIndex]) {
        const elapsed = (Date.now() - questionStartTime) / 1000;
        timePerQuestion[currentIndex] = Math.min(elapsed, TIME_LIMIT);
    }
    currentIndex = index;
    renderQuestion(currentIndex);
}

// ---------- UPDATE FUNCTIONS ----------
function updatePointsDisplay() {
    pointsDisplay.textContent = points;
}

function updateProgress() {
    const total = questions.length || 1;
    let answered = 0;
    for (let i = 0; i < selectedAnswers.length; i++) {
        if (selectedAnswers[i] !== null) answered++;
    }
    const pct = Math.round((answered / total) * 100);
    progressFill.style.width = pct + '%';
    progressLabel.textContent = pct + '% complete';
    const unanswered = total - answered;
    progressUnanswered.textContent = `Unanswered: ${unanswered}`;
    unansweredCount.textContent = unanswered;
}

function updateDashboard() {
    totalQ.textContent = questions.length || 0;
    let correct = 0, wrong = 0, locked = 0, unanswered = 0;
    for (let i = 0; i < selectedAnswers.length; i++) {
        if (lockedAnswers[i]) locked++;
        if (selectedAnswers[i] === null) {
            unanswered++;
            continue;
        }
        if (selectedAnswers[i] === questions[i]?.correct) correct++;
        else wrong++;
    }
    correctCount.textContent = correct;
    wrongCount.textContent = wrong;
    lockedCount.textContent = locked;
    unansweredCount.textContent = unanswered;
    const total = correct + wrong;
    accuracy.textContent = total ? Math.round((correct / total) * 100) + '%' : '0%';
    const avg = timePerQuestion.length ? timePerQuestion.reduce((a, b) => a + b, 0) / timePerQuestion.length : 0;
    avgTime.textContent = Math.round(avg) + 's';
    updateProgress();
    updatePointsDisplay();
}

// ---------- FINISH QUIZ ----------
async function finishQuiz() {
    if (quizCompleted) return;
    stopTimer();
    quizCompleted = true;
    quizStarted = false;
    quizArea.classList.add('hidden');
    resultPanel.classList.remove('hidden');

    let correct = 0;
    const details = [];
    for (let i = 0; i < questions.length; i++) {
        const userAns = selectedAnswers[i];
        const correctAns = questions[i].correct;
        const isCorrect = (userAns === correctAns);
        if (isCorrect) correct++;
        details.push({
            index: i,
            question: questions[i].question,
            userAns: userAns,
            correctAns: correctAns,
            isCorrect: isCorrect,
            options: questions[i].options,
            time: timePerQuestion[i] || 0,
            description: questions[i].description || '',
            hintUsed: hintUsed[i] || false,
            question_id: questions[i].id || i + 1
        });
    }
    const pct = Math.round((correct / questions.length) * 100);
    if (pct >= 70) {
        triggerCelebration();
    }

    const avgTimeVal = timePerQuestion.reduce((a, b) => a + b, 0) / questions.length;

    // Save to database
    const sessionData = {
        difficulty: difficultySelect.value,
        total_questions: questions.length,
        correct_answers: correct,
        wrong_answers: questions.length - correct,
        points_earned: points,
        average_time: avgTimeVal,
        answers: details.map(d => ({
            question_id: d.question_id,
            selected_option: d.userAns !== null ? String.fromCharCode(65 + d.userAns) : null,
            is_correct: d.isCorrect,
            time_taken: d.time,
            hint_used: d.hintUsed
        }))
    };

    try {
        await saveSession(sessionData);
        console.log('✅ Session saved, reloading history...');
        await loadHistory();
    } catch (e) {
        console.error('Error saving session:', e);
    }

    finalScore.textContent = `${correct}/${questions.length}`;
    resultTime.textContent = Math.round(avgTimeVal) + 's avg';
    resultPoints.textContent = points;

    let badge = '';
    if (pct >= 90) badge = '🌟 Excellent';
    else if (pct >= 70) badge = '👍 Good job';
    else if (pct >= 50) badge = '📚 Keep practicing';
    else badge = '💪 Review more';
    resultBadge.textContent = badge;

    let detailHtml = '';
    const letters = ['A', 'B', 'C', 'D'];
    for (let d of details) {
        const icon = d.isCorrect ? '<i class="fas fa-check-circle" style="color:#1a7a4a;"></i>' :
            '<i class="fas fa-times-circle" style="color:#b02a3a;"></i>';
        const userLetter = (d.userAns !== null) ? letters[d.userAns] : '—';
        const correctLetter = letters[d.correctAns];
        const statusBadge = d.isCorrect ?
            `<span class="correct-badge"><i class="fas fa-check"></i> correct</span>` :
            `<span class="wrong-badge"><i class="fas fa-times"></i> wrong (${correctLetter})</span>`;
        const hintInfo = d.hintUsed ? `<span style="font-size:0.7rem; color:#f5c542; margin-left:4px;">💡 hint used</span>` : '';
        const desc = d.description ? `<span style="font-size:0.7rem; color:#5d8494; margin-left:4px;">📝 ${d.description}</span>` : '';
        detailHtml += `
            <div class="result-item">
                <span class="qnum">#${d.index+1}</span>
                <span class="qtext">${d.question}</span>
                <span class="icon-result">${icon}</span>
                <span style="font-weight:500; font-size:0.8rem;">your: ${userLetter}</span>
                ${statusBadge}
                <span style="font-size:0.7rem; color:#5d8494;">${Math.round(d.time)}s</span>
                ${hintInfo}
                ${desc}
            </div>
        `;
    }
    resultDetailGrid.innerHTML = detailHtml;
    updateDashboard();
}

// ---------- RESET ----------
function resetToSetup() {
    stopTimer();
    quizStarted = false;
    quizCompleted = false;
    quizArea.classList.add('hidden');
    resultPanel.classList.add('hidden');
    setupPanel.classList.remove('hidden');
    questions = [];
    selectedAnswers = [];
    lockedAnswers = [];
    hintUsed = [];
    timePerQuestion = [];
    points = 0;
    currentIndex = 0;
    answerDescription.classList.remove('show');
    hintText.classList.remove('show');
    comedyMessage.classList.remove('show');
    updatePointsDisplay();
    updateDashboard();
    updateProgress();
    celebrationContainer.innerHTML = '';
}

// ---------- EVENT LISTENERS ----------
startQuizBtn.addEventListener('click', buildQuiz);

nextBtn.addEventListener('click', () => {
    if (quizCompleted || !quizStarted) return;
    if (selectedAnswers[currentIndex] !== null && !lockedAnswers[currentIndex]) {
        const elapsed = (Date.now() - questionStartTime) / 1000;
        timePerQuestion[currentIndex] = Math.min(elapsed, TIME_LIMIT);
    }
    if (currentIndex === questions.length - 1) {
        finishQuiz();
    } else {
        goToQuestion(currentIndex + 1);
    }
});

prevBtn.addEventListener('click', () => {
    if (quizCompleted || !quizStarted) return;
    if (currentIndex > 0) goToQuestion(currentIndex - 1);
});

restartBtn.addEventListener('click', resetToSetup);

// ---------- LOAD USER STATS & HISTORY ----------
async function loadUserStats() {
    try {
        const response = await fetch(`${SCRIPT_NAME}?api=1&action=get_user_stats`);
        const data = await response.json();
        console.log('User stats:', data);
    } catch(e) {
        console.error('Error loading user stats:', e);
    }
}

// Load history on page load
console.log('🔄 Loading history...');
loadHistory();
loadUserStats();

console.log('✅ ProQuiz loaded successfully!');
console.log('📁 File: quiz.php');
