
        // Client-side validation
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;
            let isValid = true;
            
            // Reset error states
            document.querySelectorAll('.form-group').forEach(group => {
                group.classList.remove('error');
            });
            
            // Validate email
            if (!email) {
                document.querySelector('input[name="email"]').closest('.form-group').classList.add('error');
                isValid = false;
            } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                document.querySelector('input[name="email"]').closest('.form-group').classList.add('error');
                isValid = false;
            }
            
            // Validate password
            if (!password) {
                document.querySelector('input[name="password"]').closest('.form-group').classList.add('error');
                isValid = false;
            } else if (password.length < 6) {
                document.querySelector('input[name="password"]').closest('.form-group').classList.add('error');
                isValid = false;
            }
            
            if (!isValid) {
                e.preventDefault();
            }
        });
        
        // Auto-hide error messages after 5 seconds
        const errorMessages = document.querySelector('.error-messages');
        if (errorMessages) {
            setTimeout(() => {
                errorMessages.style.opacity = '0';
                errorMessages.style.transition = 'opacity 0.5s ease';
                setTimeout(() => {
                    errorMessages.style.display = 'none';
                }, 500);
            }, 5000);
        }
  