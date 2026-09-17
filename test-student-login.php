<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>BrainPal Student Login Test</title>

  <style>
    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
      font-family: Arial, sans-serif;
      background: #f4f6f8;
    }

    .card {
      width: 100%;
      max-width: 460px;
      padding: 28px;
      background: #fff;
      border-radius: 14px;
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
    }

    h1 {
      margin: 0 0 8px;
      color: #222;
      font-size: 24px;
    }

    .subtitle {
      margin: 0 0 24px;
      color: #666;
      font-size: 14px;
      line-height: 1.5;
    }

    .form-group {
      margin-bottom: 18px;
    }

    label {
      display: block;
      margin-bottom: 7px;
      color: #333;
      font-size: 14px;
      font-weight: 600;
    }

    input {
      width: 100%;
      padding: 12px 14px;
      border: 1px solid #ccc;
      border-radius: 8px;
      outline: none;
      font-size: 15px;
    }

    input:focus {
      border-color: #4f46e5;
    }

    button {
      width: 100%;
      padding: 13px;
      border: none;
      border-radius: 8px;
      background: #4f46e5;
      color: #fff;
      font-size: 15px;
      font-weight: 600;
      cursor: pointer;
    }

    button:hover {
      background: #4338ca;
    }

    button:disabled {
      background: #999;
      cursor: not-allowed;
    }

    .result-box {
      margin-top: 20px;
      padding: 14px;
      min-height: 80px;
      border: 1px solid #ddd;
      border-radius: 8px;
      background: #f7f7f7;
      white-space: pre-wrap;
      word-break: break-word;
      font-family: Consolas, monospace;
      font-size: 13px;
    }

    .success {
      border-color: #22c55e;
      background: #f0fdf4;
    }

    .error {
      border-color: #ef4444;
      background: #fef2f2;
    }

    .info {
      border-color: #3b82f6;
      background: #eff6ff;
    }

    .warning {
      border-color: #f59e0b;
      background: #fffbeb;
    }
  </style>
</head>

<body>

  <div class="card">

    <h1>BrainPal Student Login Test</h1>

    <p class="subtitle">
      Test page para sa actual student login endpoint ng BrainPal.
    </p>

    <form id="loginForm">

      <div class="form-group">
        <label for="email">Student Email</label>

        <input
          type="email"
          id="email"
          name="email"
          value="danielleannegrospe@gmail.com"
          autocomplete="username"
          required
        >
      </div>

      <div class="form-group">
        <label for="password">Password</label>

        <input
          type="password"
          id="password"
          name="password"
          autocomplete="current-password"
          required
        >
      </div>

      <button type="submit" id="loginButton">
        TEST STUDENT LOGIN
      </button>

    </form>

    <div id="result" class="result-box info">
Waiting for test...
    </div>

  </div>

  <script>
    const form = document.getElementById('loginForm');
    const emailInput = document.getElementById('email');
    const passwordInput = document.getElementById('password');
    const loginButton = document.getElementById('loginButton');
    const resultBox = document.getElementById('result');

    // ACTUAL student login endpoint from the BrainPal project
    const API_URL = './login/login.php';

    function showResult(message, type = 'info') {
      resultBox.className = 'result-box ' + type;
      resultBox.textContent = message;
    }

    form.addEventListener('submit', async function (event) {

      event.preventDefault();

      const email = emailInput.value.trim();
      const password = passwordInput.value;

      if (!email) {
        showResult(
          'Please enter the student email.',
          'error'
        );
        return;
      }

      if (!password) {
        showResult(
          'Please enter the password.',
          'error'
        );
        return;
      }

      loginButton.disabled = true;
      loginButton.textContent = 'TESTING...';

      showResult(
        'Sending student login request...',
        'info'
      );

      try {

        const response = await fetch(API_URL, {
          method: 'POST',

          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'ngrok-skip-browser-warning': 'true'
          },

          body: JSON.stringify({
            email: email,
            password: password
          })
        });

        const rawText = await response.text();

        let data;

        try {

          data = JSON.parse(rawText);

        } catch (parseError) {

          showResult(
            'The server did not return valid JSON.\n\n' +
            'HTTP Status: ' + response.status +
            '\n\nRaw Response:\n' +
            rawText,
            'error'
          );

          return;
        }

        if (
          response.ok &&
          data.status === 'success'
        ) {

          showResult(
            'STUDENT LOGIN SUCCESSFUL\n\n' +
            JSON.stringify(data, null, 2),
            'success'
          );

          return;
        }

        if (data.status === 'unverified') {

          showResult(
            'LOGIN REACHED THE STUDENT ACCOUNT, BUT EMAIL IS NOT VERIFIED.\n\n' +
            JSON.stringify(data, null, 2),
            'warning'
          );

          return;
        }

        showResult(
          'STUDENT LOGIN FAILED\n\n' +
          'HTTP Status: ' + response.status +
          '\n\n' +
          JSON.stringify(data, null, 2),
          'error'
        );

      } catch (error) {

        console.error(
          'STUDENT LOGIN TEST ERROR:',
          error
        );

        showResult(
          'REQUEST FAILED\n\n' +
          'Message: ' +
          error.message +
          '\n\n' +
          'Possible causes:\n' +
          '- Apache is not running\n' +
          '- login/login.php is missing\n' +
          '- CORS problem\n' +
          '- PHP error\n' +
          '- Wrong project folder',
          'error'
        );

      } finally {

        loginButton.disabled = false;
        loginButton.textContent =
          'TEST STUDENT LOGIN';

      }

    });
  </script>

</body>
</html>