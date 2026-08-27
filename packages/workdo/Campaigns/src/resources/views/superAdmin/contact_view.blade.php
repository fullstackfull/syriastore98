<style>
    .middle {
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        width: 100%;
        text-align: center;
    }

    .contact-inner {
        display: inline-block;
        width: 40px;
        height: 40px;
        background: #f1f1f1;
        border-radius: 30%;
        box-shadow: 0 5px 15px -5px #00000070;
        color: #3498db;
        overflow: hidden;
        position: relative;
        display: flex;
        justify-content: center;
        align-items: center;
    }

    .contact-inner img {
        line-height: 40px;
        font-size: 18px;
        width: 22px;
        height: 22px;
        transition: 0.2s linear;
    }

    .contact-inner:hover img {
        transform: scale(1.3);
        color: #f1f1f1;
    }

    .contact-inner::before {
        content: "";
        position: absolute;
        width: 120%;
        height: 120%;
        background: var(--color-customColor);
        transform: rotate(45deg);
        left: -110%;
        top: 90%;
    }

    .contact-inner:hover::before {
        animation: aaa 0.7s 1;
        top: -10%;
        left: -10%;
    }

    @keyframes aaa {
        0% {
            left: -110%;
            top: 90%;
        }

        50% {
            left: 10%;
            top: -30%;
        }

        100% {
            top: -10%;
            left: -10%;
        }
    }

    .contact-list ul {
        list-style: none;
    }

    .contact-inner-img {
        border: 1px solid #ced4da;
        border-top: 0;
        border-left: 0;
        border-bottom: 0;
    }

    .contact-modal ul.list-none {
        padding-left: 10px;
    }
</style>
<div class="contact-modal d-flex flex-column gap-2">

    <div class="col-12 text-center py-4">
        <div class="alert alert-warning" role="alert">
            <h6 class="alert-heading">{{ __('No Contact Details Available') }}</h6>
            <p>{{ __('It looks like we don\'t have any contact information for this business yet.') }}</p>
        </div>
    </div>

</div>
