

document.getElementById('expiryDate').addEventListener('input', function (e) {
    var input = e.target.value.replace(/\D/g, '').substring(0,4);
    var month = input.substring(0, 2);
    var year = input.substring(2, 4);
    
    if(input.length > 2) {
        e.target.value = month + '/' + year;
    } else {
        e.target.value = month;
    }
});