function loadData() {
    viewModel.tableLoaded(false);
    var posting = $.post('/search', {type: "author", query: "C.S. Lewis"}, "json");
    posting.done(parseData);
}
function parseData(data) {
    var json = JSON.parse(data);
    if (json.msg === "success") {
        for (var counter = 0; counter < json.data.length; counter++) {
            var book = json.data[counter];
            viewModel.bookTable.push({'title': book.title, 'isbn': book.isbn
                , 'desc': book.desc});
        }
    } else {

    }
    viewModel.tableLoaded(true);

}