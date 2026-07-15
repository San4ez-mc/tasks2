  (function () {
    const openButtons = document.querySelectorAll('.js-employee-edit-open');
    const closeButtons = document.querySelectorAll('.js-employee-edit-close');

    function closeAllRows() {
      document.querySelectorAll('.employee-edit-row').forEach((row) => {
        row.style.display = 'none';
      });
    }

    openButtons.forEach((button) => {
      button.addEventListener('click', function (event) {
        event.preventDefault();
        const employeeId = this.getAttribute('data-employee-id');
        const row = document.getElementById('employee-edit-row-' + employeeId);
        if (!row) {
          return;
        }

        const willOpen = row.style.display !== 'table-row';
        closeAllRows();
        row.style.display = willOpen ? 'table-row' : 'none';
      });
    });

    closeButtons.forEach((button) => {
      button.addEventListener('click', function () {
        const employeeId = this.getAttribute('data-employee-id');
        const row = document.getElementById('employee-edit-row-' + employeeId);
        if (row) {
          row.style.display = 'none';
        }
      });
    });
  })();